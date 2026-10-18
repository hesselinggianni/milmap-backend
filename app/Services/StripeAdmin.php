<?php

namespace App\Services;

use App\Models\ConnectOrderInvoice;
use Illuminate\Support\Facades\{Cache, DB};
use Stripe\StripeClient;

/**
 * Stripe-beheer vanuit MilMap Admin: facturen inzien, betaald markeren, crediteren, abonnementen wijzigen of stoppen,
 * en saldo en kosten. Alles gaat via de Stripe-API met de sleutel van de server; ontbrekende rechten komen als Stripe-fout terug.
 */
class StripeAdmin
{
    protected function client(): StripeClient
    {
        abort_unless(config('billing.stripe_secret'), 422, 'Stripe is nog niet geconfigureerd (geen secret key).');
        return new StripeClient(['api_key' => config('billing.stripe_secret')]);
    }

    // ── Facturen ─────────────────────────────────────────────────────────

    /** @param array{status?:?string,q?:?string,starting_after?:?string,limit?:?int} $filters */
    public function invoices(array $filters): array
    {
        $stripe = $this->client();
        $params = ['limit' => min(100, max(1, (int) ($filters['limit'] ?? 25))), 'expand' => ['data.customer']];
        if (!empty($filters['status']) && $filters['status'] !== 'all') $params['status'] = $filters['status'];
        if (!empty($filters['starting_after'])) $params['starting_after'] = $filters['starting_after'];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            if (str_starts_with($q, 'in_')) {
                return ['data' => [$this->invoiceRow($stripe->invoices->retrieve($q, ['expand' => ['customer']]))], 'has_more' => false, 'next' => null];
            }
            if (str_starts_with($q, 'cus_')) $params['customer'] = $q;
            elseif (str_contains($q, '@')) {
                $customers = $stripe->customers->all(['email' => $q, 'limit' => 5])->data;
                if (!$customers) return ['data' => [], 'has_more' => false, 'next' => null];
                $params['customer'] = $customers[0]->id;
            }
        }
        $list = $stripe->invoices->all($params);
        $data = $list->data;
        return ['data' => array_map(fn ($i) => $this->invoiceRow($i), $data), 'has_more' => (bool) $list->has_more,
            'next' => $list->has_more && $data ? $data[array_key_last($data)]->id : null];
    }

    public function invoice(string $id): array
    {
        $stripe = $this->client();
        $invoice = $stripe->invoices->retrieve($id, ['expand' => ['customer']]);
        $lines = $invoice->lines->data ?? [];
        if ($invoice->lines->has_more ?? false) $lines = iterator_to_array($stripe->invoices->allLines($id, ['limit' => 100])->autoPagingIterator(), false);
        $subscriptionId = $this->subscriptionId($invoice);
        $order = ConnectOrderInvoice::where('stripe_invoice_id', $id)->with('order.organization')->first()?->order;
        return ['invoice' => $this->invoiceRow($invoice) + ['memo' => $invoice->description ?? null],
            'lines' => array_map(fn ($l) => $this->lineRow($l), $lines),
            'credit_invoices' => $this->creditInvoicesOf($id),
            'credited_lines' => array_values(array_filter(explode(',', (string) ($invoice->metadata->credited_lines ?? '')))),
            'subscription' => $subscriptionId ? $this->subscriptionRow($stripe->subscriptions->retrieve($subscriptionId, [])) : null,
            'connect_order' => $order ? ['id' => $order->id, 'organization_id' => $order->organization_id, 'organization' => $order->organization?->name,
                'seats' => $order->seats, 'term_months' => $order->term_months, 'status' => $order->status] : null];
    }

    /** Als betaald buiten Stripe markeren (bijv. na een bankoverschrijving); een concept wordt eerst definitief gemaakt. */
    public function markPaid(string $id, ?string $note = null): array
    {
        $stripe = $this->client();
        $invoice = $stripe->invoices->retrieve($id, []);
        abort_if($invoice->status === 'paid', 422, 'Deze factuur is al betaald.');
        abort_if($invoice->status === 'void', 422, 'Een vervallen factuur kan niet worden betaald.');
        if ($invoice->status === 'draft') $invoice = $stripe->invoices->finalizeInvoice($id, ['auto_advance' => false], ['idempotency_key' => 'admin-finalize-'.$id]);
        // Stripe kent bij 'betaald buiten Stripe' geen opmerking: die komt in de metadata van de factuur.
        if (trim((string) $note) !== '') $stripe->invoices->update($id, ['metadata' => ['paid_out_of_band_note' => mb_substr(trim($note), 0, 480)]]);
        $paid = $stripe->invoices->pay($id, ['paid_out_of_band' => true], ['idempotency_key' => 'admin-pay-'.$id]);
        // Connect-bestellingen activeren direct, ook als de webhook later of niet aankomt.
        return ['invoice' => $this->invoiceRow($paid), 'connect_synced' => $this->syncConnect('invoice.paid', $paid)];
    }

    /**
     * Crediteren zonder Stripe-creditnota's: een nieuwe factuur met de geselecteerde regels als negatief bedrag (met dezelfde btw-tarieven),
     * definitief gemaakt zodat het bedrag als tegoed bij de klant komt. Een nog openstaande factuur die daarmee volledig wordt gedekt, wordt
     * met dat tegoed betaald: zo strepen de twee elkaar weg. Op de oorspronkelijke factuur staat in de metadata welke creditfactuur dat deed.
     * @param array{lines:array<int,array{id:string,quantity?:int}>,memo?:?string} $data
     * @return array{credit_invoice:array,invoice:array,settled:bool,settle_error:?string}
     */
    public function creditInvoice(string $id, array $data): array
    {
        $stripe = $this->client();
        $invoice = $stripe->invoices->retrieve($id, ['expand' => ['customer']]);
        abort_if($invoice->status === 'draft', 422, 'Een concept kan niet worden gecrediteerd: rond de factuur eerst af of verwijder het concept in Stripe.');
        abort_unless(in_array($invoice->status, ['open', 'paid', 'uncollectible'], true), 422, 'Alleen een openstaande of betaalde factuur kan worden gecrediteerd.');
        abort_if(!empty($invoice->metadata->credits_invoice ?? null), 422, 'Dit is zelf een creditfactuur en kan niet nog eens worden gecrediteerd.');
        $already = array_values(array_filter(explode(',', (string) ($invoice->metadata->credited_lines ?? ''))));
        $available = [];
        foreach ($invoice->lines->data ?? [] as $line) $available[$line->id] = $line;
        $number = $invoice->number ?? $id;
        $items = [];
        foreach ($data['lines'] as $row) {
            $line = $available[$row['id']] ?? null;
            abort_unless($line, 422, 'Een geselecteerde regel hoort niet bij deze factuur.');
            abort_if(in_array($line->id, $already, true), 422, "De regel '".($line->description ?? $line->id)."' is al gecrediteerd.");
            $max = max(1, (int) ($line->quantity ?? 1)); $quantity = (int) ($row['quantity'] ?? $max);
            abort_unless($quantity >= 1 && $quantity <= $max, 422, "Het aantal bij '".($line->description ?? $line->id)."' moet tussen 1 en {$max} liggen.");
            $amount = (int) round(((int) $line->amount) * $quantity / $max);
            abort_if($amount <= 0, 422, "De regel '".($line->description ?? $line->id)."' heeft geen bedrag om te crediteren.");
            $taxes = (array) ($line->taxes ?? []);
            $items[] = ['line' => $line->id, 'amount' => -$amount, 'description' => mb_substr('Creditering: '.($line->description ?? 'regel').($max > 1 ? " ({$quantity} van {$max})" : '').' · factuur '.$number, 0, 480),
                'tax_rates' => array_values(array_filter(array_map(fn ($t) => $t->tax_rate_details->tax_rate ?? null, $taxes))), 'tax_behavior' => $taxes[0]->tax_behavior ?? null];
        }
        $customerId = is_object($invoice->customer ?? null) ? $invoice->customer->id : $invoice->customer;
        $hash = substr(md5(json_encode($items)), 0, 16);
        $credit = $stripe->invoices->create(['customer' => $customerId, 'currency' => $invoice->currency, 'collection_method' => 'send_invoice', 'days_until_due' => 1, 'auto_advance' => false,
            'pending_invoice_items_behavior' => 'exclude', 'automatic_tax' => ['enabled' => false],
            'description' => mb_substr(trim("Creditfactuur voor factuur {$number}. ".($data['memo'] ?? '')), 0, 480),
            'metadata' => ['credits_invoice' => $id, 'credits_invoice_number' => (string) $number]], ['idempotency_key' => "admin-credit-invoice-{$id}-{$hash}"]);
        try {
            foreach ($items as $item) {
                $stripe->invoiceItems->create(array_filter(['customer' => $customerId, 'invoice' => $credit->id, 'currency' => $invoice->currency, 'amount' => $item['amount'], 'description' => $item['description'],
                    'tax_rates' => $item['tax_rates'] ?: null, 'tax_behavior' => $item['tax_rates'] ? $item['tax_behavior'] : null, 'metadata' => ['credits_line' => $item['line']]], fn ($v) => $v !== null),
                    ['idempotency_key' => "admin-credit-item-{$id}-{$item['line']}-{$hash}"]);
            }
        } catch (\Throwable $e) {
            // Geen halve creditfactuur achterlaten: het concept zonder regels (of met een deel ervan) verdwijnt weer.
            try { $stripe->invoices->delete($credit->id, []); } catch (\Throwable) { /* Stripe geeft dan zelf de foutmelding hierboven */ }
            throw $e;
        }
        // Definitief maken: een negatief totaal komt als tegoed bij de klant en de creditfactuur is daarmee afgehandeld; anders handmatig op betaald zetten.
        $credit = $stripe->invoices->finalizeInvoice($credit->id, ['auto_advance' => false], ['idempotency_key' => "admin-credit-finalize-{$credit->id}"]);
        if ($credit->status !== 'paid') $credit = $stripe->invoices->pay($credit->id, ['paid_out_of_band' => true], ['idempotency_key' => "admin-credit-pay-{$credit->id}"]);
        $creditNumber = $credit->number ?? $credit->id;
        $creditedLines = implode(',', array_merge($already, array_column($items, 'line')));
        $settled = false; $settleError = null;
        if ($invoice->status === 'open' && abs((int) $credit->total) >= (int) $invoice->amount_due) {
            // Volledig gedekt: de oorspronkelijke factuur met het tegoed betalen. De metadata gaat vooraf mee, zodat de webhook van die betaling (Connect) weet dat het een verrekening is en geen echte betaling.
            $stripe->invoices->update($id, ['metadata' => ['credited_by' => (string) $creditNumber, 'credited_lines' => $creditedLines, 'settled_note' => mb_substr("Verrekend met creditfactuur {$creditNumber}", 0, 480)]]);
            try {
                $paid = $stripe->invoices->pay($id, [], ['idempotency_key' => "admin-credit-settle-{$id}-{$credit->id}"]);
                $settled = $paid->status === 'paid';
                if ($settled) $this->syncConnect('invoice.paid', $paid);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                StripeErrors::record($e, 'admin.credit_invoice_settle', ['stripe_object' => $id, 'credit_invoice' => $credit->id], true);
                $settleError = StripeErrors::scrub($e->getMessage());
            }
            if (!$settled) $stripe->invoices->update($id, ['metadata' => ['credited_by' => '', 'settled_note' => '', 'credit_invoices' => (string) $creditNumber]]);
        } else {
            $stripe->invoices->update($id, ['metadata' => ['credited_lines' => $creditedLines, 'credit_invoices' => trim(((string) ($invoice->metadata->credit_invoices ?? '')).','.$creditNumber, ',')]]);
        }
        return ['credit_invoice' => $this->invoiceRow($credit), 'invoice' => $this->invoiceRow($stripe->invoices->retrieve($id, ['expand' => ['customer']])), 'settled' => $settled, 'settle_error' => $settleError];
    }

    /** De creditfacturen die via MilMap Admin op deze factuur zijn gemaakt (uit ons eigen actielogboek). */
    private function creditInvoicesOf(string $id): array
    {
        return DB::table('stripe_admin_actions')->where('action', 'credit_invoice')->where('stripe_object', $id)->where('succeeded', true)->orderByDesc('id')->get()
            ->map(function ($r) {
                $result = json_decode((string) $r->payload, true)['result'] ?? null;
                return is_array($result) && isset($result['id']) ? $result + ['created_at' => $r->created_at] : null;
            })->filter()->values()->all();
    }

    // ── Abonnementen ─────────────────────────────────────────────────────

    /** @param array{quantity?:int,cancel_at_period_end?:bool} $data */
    public function updateSubscription(string $id, array $data): array
    {
        $stripe = $this->client();
        $subscription = $stripe->subscriptions->retrieve($id, []);
        $params = [];
        if (array_key_exists('cancel_at_period_end', $data)) $params['cancel_at_period_end'] = (bool) $data['cancel_at_period_end'];
        if (isset($data['quantity'])) {
            $items = $subscription->items->data ?? [];
            abort_unless(count($items) === 1, 422, 'Dit abonnement heeft meerdere onderdelen: pas het aantal in Stripe zelf aan.');
            $params['items'] = [['id' => $items[0]->id, 'quantity' => (int) $data['quantity']]];
            $params['proration_behavior'] = 'none';
        }
        abort_if(!$params, 422, 'Er is niets om bij te werken.');
        $updated = $stripe->subscriptions->update($id, $params);
        if (array_key_exists('cancel_at_period_end', $params)) $this->syncConnect('customer.subscription.updated', $updated);
        return ['subscription' => $this->subscriptionRow($updated)];
    }

    /** Stopt het abonnement meteen (zonder nieuwe factuur of verrekening); toegang die al is betaald blijft tot de einddatum van de licentie. */
    public function cancelSubscription(string $id): array
    {
        $stripe = $this->client();
        $cancelled = $stripe->subscriptions->cancel($id, ['invoice_now' => false, 'prorate' => false]);
        $this->syncConnect('customer.subscription.deleted', $cancelled);
        return ['subscription' => $this->subscriptionRow($cancelled)];
    }

    // ── Saldo en kosten ──────────────────────────────────────────────────

    /** Saldo nu, plus per maand de bruto omzet, Stripe-kosten, terugbetalingen en uitbetalingen (maximaal 5000 mutaties). */
    public function balance(int $months): array
    {
        $months = min(12, max(1, $months));
        return Cache::remember("stripe-admin-balance:{$months}", 300, function () use ($months) {
            $stripe = $this->client();
            $balance = $stripe->balance->retrieve([]);
            $amounts = fn ($rows) => array_map(fn ($r) => ['amount' => (int) $r->amount, 'currency' => strtoupper($r->currency)], (array) $rows);
            $from = now()->startOfMonth()->subMonths($months - 1);
            $rows = []; $seen = 0; $truncated = false;
            foreach ($stripe->balanceTransactions->all(['limit' => 100, 'created' => ['gte' => $from->timestamp]])->autoPagingIterator() as $t) {
                if (++$seen > 5000) { $truncated = true; break; }
                $currency = strtoupper($t->currency); $month = date('Y-m', $t->created); $key = $month.'|'.$currency;
                $rows[$key] ??= ['month' => $month, 'currency' => $currency, 'gross' => 0, 'fees' => 0, 'refunds' => 0, 'payouts' => 0, 'count' => 0];
                $type = $t->type ?? '';
                if (in_array($type, ['charge', 'payment'], true)) { $rows[$key]['gross'] += (int) $t->amount; $rows[$key]['count']++; }
                elseif (in_array($type, ['refund', 'payment_refund'], true)) $rows[$key]['refunds'] += (int) $t->amount;
                elseif ($type === 'payout') $rows[$key]['payouts'] += (int) $t->amount;
                // Kosten staan op de mutatie zelf (fee) of als eigen mutatie van het type kosten (negatief bedrag).
                $rows[$key]['fees'] += (int) ($t->fee ?? 0);
                if (($t->reporting_category ?? '') === 'fee') $rows[$key]['fees'] += -(int) $t->amount;
            }
            krsort($rows);
            return ['available' => $amounts($balance->available ?? []), 'pending' => $amounts($balance->pending ?? []),
                'months' => array_values(array_map(fn ($r) => $r + ['net' => $r['gross'] - $r['fees']], $rows)), 'truncated' => $truncated, 'fetched_at' => now()->toIso8601String()];
        });
    }

    // ── Hulpjes ──────────────────────────────────────────────────────────

    private function invoiceRow(object $i): array
    {
        $customer = is_object($i->customer ?? null) ? $i->customer : null;
        return ['id' => $i->id, 'number' => $i->number ?? null, 'status' => $i->status, 'currency' => strtoupper($i->currency ?? 'eur'),
            'total' => (int) ($i->total ?? 0), 'amount_due' => (int) ($i->amount_due ?? 0), 'amount_paid' => (int) ($i->amount_paid ?? 0),
            'customer_id' => $customer->id ?? (is_string($i->customer ?? null) ? $i->customer : null),
            'customer_name' => $i->customer_name ?? $customer->name ?? null, 'customer_email' => $i->customer_email ?? $customer->email ?? null,
            'created' => $i->created ?? null, 'due_date' => $i->due_date ?? null, 'collection_method' => $i->collection_method ?? null,
            'hosted_invoice_url' => $i->hosted_invoice_url ?? null, 'invoice_pdf' => $i->invoice_pdf ?? null, 'subscription_id' => $this->subscriptionId($i),
            'credits_invoice' => $i->metadata->credits_invoice_number ?? null, 'credited_by' => ($i->metadata->credited_by ?? '') ?: null, 'note' => ($i->metadata->paid_out_of_band_note ?? '') ?: null];
    }

    private function lineRow(object $l): array
    {
        return ['id' => $l->id, 'description' => $l->description ?? null, 'amount' => (int) ($l->amount ?? 0), 'quantity' => (int) ($l->quantity ?? 1),
            'currency' => strtoupper($l->currency ?? 'eur'), 'period_start' => $l->period->start ?? null, 'period_end' => $l->period->end ?? null];
    }

    private function subscriptionRow(object $s): array
    {
        $items = $s->items->data ?? [];
        return ['id' => $s->id, 'status' => $s->status, 'cancel_at_period_end' => (bool) ($s->cancel_at_period_end ?? false), 'canceled_at' => $s->canceled_at ?? null,
            'collection_method' => $s->collection_method ?? null, 'current_period_end' => $items[0]->current_period_end ?? $s->current_period_end ?? null,
            'items' => array_map(fn ($i) => ['id' => $i->id, 'quantity' => (int) ($i->quantity ?? 1), 'unit_amount' => $i->price->unit_amount ?? null,
                'currency' => strtoupper($i->price->currency ?? 'eur'), 'interval' => $i->price->recurring->interval ?? null, 'interval_count' => $i->price->recurring->interval_count ?? null], $items)];
    }

    private function subscriptionId(object $invoice): ?string
    {
        $s = $invoice->parent->subscription_details->subscription ?? $invoice->subscription ?? null;
        return is_string($s) ? $s : ($s->id ?? null);
    }

    /** Connect-bestellingen volgen een wijziging in Stripe direct (hetzelfde als de webhook); geen Connect-bestelling = niets te doen. */
    private function syncConnect(string $type, object $object): bool
    {
        try {
            return app(ConnectBilling::class)->webhook($type, $object);
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }
}
