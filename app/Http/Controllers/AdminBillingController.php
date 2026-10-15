<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Stripe\StripeClient;
use Throwable;

/**
 * Admin-only Stripe configuration: list the live products/prices straight from
 * the connected Stripe account and let an admin map each MilMap plan slot
 * (pro_monthly, …) to a Stripe Price ID. The chosen mapping is stored in the
 * settings table and overrides the .env defaults at checkout time.
 */
class AdminBillingController extends Controller
{
    // 'lifetime' = het AppSumo lifetime-deal-product. De admin koppelt hier de
    // Stripe-prijs (100%-korting-abonnement) waaraan verzilverde codes worden gekoppeld.
    // 'team_extra_seat' = de meter-prijs (€2) voor elke seat bóven de inbegrepen
    // seats (config/teams.php). Het team-abonnement rekent extra leden hierop af.
    public const PLAN_KEYS = ['pro_monthly', 'pro_yearly', 'team_monthly', 'team_yearly', 'lifetime', 'team_extra_seat'];
    public const SETTING_KEY = 'billing_prices';

    // NB: bewust GEEN StripeClient in de constructor. Als de Stripe-secret
    // ontbreekt (of de config-cache stale is) gooit `new StripeClient(null)`
    // een InvalidArgumentException. Omdat de constructor bij ELKE actie draait,
    // crashte daardoor ook price-map / save-price-map met een 500 — terwijl die
    // Stripe helemaal niet nodig hebben. De client wordt nu pas in prices()
    // aangemaakt, ná de secret-check en binnen een try/catch.

    /**
     * The .env-configured defaults for each plan slot.
     */
    public static function defaultMap(): array
    {
        return [
            'pro_monthly'  => config('billing.stripe_price_pro_monthly'),
            'pro_yearly'   => config('billing.stripe_price_pro_yearly'),
            'team_monthly' => config('billing.stripe_price_team_monthly'),
            'team_yearly'  => config('billing.stripe_price_team_yearly'),
            'lifetime'     => config('billing.appsumo_price'),
            'team_extra_seat' => config('billing.stripe_price_team_extra_seat'),
        ];
    }

    /**
     * The effective mapping: stored overrides on top of the .env defaults.
     */
    public static function effectiveMap(): array
    {
        $map = self::defaultMap();
        $overrides = Setting::get(self::SETTING_KEY, []);
        if (is_array($overrides)) {
            foreach (self::PLAN_KEYS as $k) {
                if (! empty($overrides[$k])) {
                    $map[$k] = $overrides[$k];
                }
            }
        }

        return $map;
    }

    /**
     * GET /api/v1/admin/billing/prices
     * All active recurring prices from Stripe, with their product info.
     */
    public function prices()
    {
        if (! config('billing.stripe_secret')) {
            return response()->json(['message' => 'Stripe is nog niet geconfigureerd (geen secret key).'], 422);
        }

        try {
            $stripe = new StripeClient(config('billing.stripe_secret'));
            $prices = $stripe->prices->all([
                'active' => true,
                'limit'  => 100,
                'expand' => ['data.product'],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Kon Stripe-prijzen niet laden: ' . $e->getMessage(),
            ], 422);
        }

        $out = [];
        foreach ($prices->data as $p) {
            $product = $p->product ?? null;
            $out[] = [
                'id'           => $p->id,
                'product_id'   => is_object($product) ? ($product->id ?? null) : (is_string($product) ? $product : null),
                'product_name' => is_object($product) ? ($product->name ?? '') : '',
                'nickname'     => $p->nickname,
                'amount'       => $p->unit_amount,                 // in cents
                'currency'     => strtoupper((string) $p->currency),
                'interval'     => $p->recurring->interval ?? null, // month | year | null
                'type'         => $p->type,                        // recurring | one_time
            ];
        }

        // Recurring prices first, then by product name.
        usort($out, function ($a, $b) {
            if (($a['type'] === 'recurring') !== ($b['type'] === 'recurring')) {
                return $a['type'] === 'recurring' ? -1 : 1;
            }
            return strcmp($a['product_name'], $b['product_name']);
        });

        return response()->json(['prices' => $out]);
    }

    /**
     * GET /api/v1/admin/billing/price-map
     * The current plan → price mapping plus which slots come from overrides.
     */
    public function priceMap()
    {
        $overrides = Setting::get(self::SETTING_KEY, []);
        $overrides = is_array($overrides) ? $overrides : [];

        return response()->json([
            'map'      => self::effectiveMap(),
            'defaults' => self::defaultMap(),
            'overridden' => array_values(array_filter(
                self::PLAN_KEYS,
                fn ($k) => ! empty($overrides[$k])
            )),
        ]);
    }

    /**
     * PUT /api/v1/admin/billing/price-map
     * Persist the admin-selected Stripe Price IDs per plan slot.
     */
    public function savePriceMap(Request $request)
    {
        $data = $request->validate([
            'pro_monthly'  => ['nullable', 'string', 'max:255'],
            'pro_yearly'   => ['nullable', 'string', 'max:255'],
            'team_monthly' => ['nullable', 'string', 'max:255'],
            'team_yearly'  => ['nullable', 'string', 'max:255'],
            'lifetime'     => ['nullable', 'string', 'max:255'],
            'team_extra_seat' => ['nullable', 'string', 'max:255'],
        ]);

        // Keep only the known plan keys, drop empties.
        $clean = [];
        foreach (self::PLAN_KEYS as $k) {
            if (! empty($data[$k])) {
                $clean[$k] = $data[$k];
            }
        }

        Setting::put(self::SETTING_KEY, $clean);

        // Nieuwe koppeling gekozen → de live-prijscache is verouderd. Leeg hem
        // (via de centrale helper zodat de cache-key op één plek staat) zodat
        // checkout/account direct de zojuist gekozen prijzen tonen.
        BillingController::forgetPricingCache();

        return response()->json([
            'ok'  => true,
            'map' => self::effectiveMap(),
        ]);
    }

    /**
     * POST /api/v1/admin/billing/flush-cache
     * Leeg handmatig de live-prijscache. Handig wanneer een admin het bedrag van
     * een prijs in Stripe heeft aangepast zonder de koppeling te wijzigen — de
     * volgende aanvraag haalt de actuele bedragen dan opnieuw bij Stripe op.
     */
    public function flushPricingCache()
    {
        BillingController::forgetPricingCache();

        return response()->json(['ok' => true]);
    }

    /**
     * Stripe-facturen binnen een periode (op aanmaakdatum), via auto-paging.
     * Filters: from/to (Y-m-d), status (paid|open|void|uncollectible|draft|all).
     */
    private function invoicesFor(Request $request): array
    {
        $data = $request->validate([
            'from'   => ['nullable', 'date'],
            'to'     => ['nullable', 'date'],
            'status' => ['nullable', 'in:all,paid,open,void,uncollectible,draft'],
        ]);

        $params = ['limit' => 100, 'expand' => ['data.customer']];
        $created = [];
        if (! empty($data['from'])) $created['gte'] = strtotime($data['from'] . ' 00:00:00');
        if (! empty($data['to']))   $created['lte'] = strtotime($data['to'] . ' 23:59:59');
        if ($created) $params['created'] = $created;
        if (! empty($data['status']) && $data['status'] !== 'all') $params['status'] = $data['status'];

        $stripe = new StripeClient(config('billing.stripe_secret'));
        $out = [];
        foreach ($stripe->invoices->all($params)->autoPagingIterator() as $inv) {
            $out[] = $inv;
        }

        return $out;
    }

    private function stripeGuard()
    {
        if (! config('billing.stripe_secret')) {
            return response()->json(['message' => 'Stripe is nog niet geconfigureerd (geen secret key).'], 422);
        }
        return null;
    }

    /**
     * GET /api/v1/admin/billing/invoices/export.csv
     * CSV-export van Stripe-facturen (puntkomma, UTF-8 BOM voor Excel-NL).
     */
    public function exportInvoicesCsv(Request $request)
    {
        if ($r = $this->stripeGuard()) return $r;
        @set_time_limit(300);

        try {
            $invoices = $this->invoicesFor($request);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Kon Stripe-facturen niet laden: ' . $e->getMessage()], 422);
        }

        $money = fn ($cents) => $cents === null ? '' : number_format($cents / 100, 2, ',', '');
        $date  = fn ($ts) => $ts ? date('Y-m-d', $ts) : '';

        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, [
            'Factuurnummer', 'Stripe ID', 'Datum', 'Betaald op', 'Status', 'Klant', 'E-mail',
            'Land', 'Btw-nummer', 'Omschrijving', 'Valuta', 'Subtotaal', 'Btw', 'Totaal',
            'Betaald', 'Openstaand', 'Abonnement', 'PDF-link',
        ], ';');

        foreach ($invoices as $inv) {
            $customer = is_object($inv->customer) ? $inv->customer : null;
            $taxIds = collect($inv->customer_tax_ids ?? [])->map(fn ($t) => $t->value ?? '')->filter()->implode(', ');
            $lines = collect($inv->lines->data ?? [])->map(fn ($l) => $l->description ?? '')->filter()->implode(' | ');
            $tax = collect($inv->total_taxes ?? $inv->total_tax_amounts ?? [])->sum(fn ($t) => $t->amount ?? 0);
            $subscription = $inv->parent->subscription_details->subscription ?? ($inv->subscription ?? '');

            fputcsv($fh, [
                $inv->number ?? '',
                $inv->id,
                $date($inv->created),
                $date($inv->status_transitions->paid_at ?? null),
                $inv->status,
                $inv->customer_name ?: ($customer->name ?? ''),
                $inv->customer_email ?: ($customer->email ?? ''),
                $inv->customer_address->country ?? ($customer->address->country ?? ''),
                $taxIds,
                $lines,
                strtoupper((string) $inv->currency),
                $money($inv->subtotal),
                $money($tax),
                $money($inv->total),
                $money($inv->amount_paid),
                $money($inv->amount_remaining),
                is_string($subscription) ? $subscription : ($subscription->id ?? ''),
                $inv->invoice_pdf ?? '',
            ], ';');
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $this->exportName($request, 'csv') . '"',
        ]);
    }

    /**
     * GET /api/v1/admin/billing/invoices/pdfs.zip
     * Alle factuur-PDF's uit Stripe gebundeld in één ZIP (max 1000 per keer).
     */
    public function downloadInvoicePdfs(Request $request)
    {
        if ($r = $this->stripeGuard()) return $r;
        @set_time_limit(900);

        try {
            $invoices = array_values(array_filter($this->invoicesFor($request), fn ($i) => ! empty($i->invoice_pdf)));
        } catch (Throwable $e) {
            return response()->json(['message' => 'Kon Stripe-facturen niet laden: ' . $e->getMessage()], 422);
        }

        if (! $invoices) {
            return response()->json(['message' => 'Geen facturen met PDF gevonden in deze periode.'], 404);
        }
        if (count($invoices) > 1000) {
            return response()->json(['message' => count($invoices) . ' facturen gevonden — kies een kortere periode (max 1000 per download).'], 422);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'inv') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $failed = [];
        foreach (array_chunk($invoices, 10) as $chunk) {
            $responses = Http::pool(fn ($pool) => array_map(
                fn ($inv) => $pool->as($inv->id)->timeout(60)->retry(2, 500, throw: false)->get($inv->invoice_pdf),
                $chunk
            ));
            foreach ($chunk as $inv) {
                $res = $responses[$inv->id] ?? null;
                $name = date('Y-m-d', $inv->created) . '_' . preg_replace('/[^A-Za-z0-9_-]/', '-', $inv->number ?: $inv->id) . '.pdf';
                if ($res instanceof \Illuminate\Http\Client\Response && $res->successful()) {
                    $zip->addFromString($name, $res->body());
                } else {
                    $failed[] = ($inv->number ?: $inv->id) . "\t" . ($inv->invoice_pdf ?? '');
                }
            }
        }
        if ($failed) {
            $zip->addFromString('_niet-gedownload.txt', "Deze facturen konden niet worden opgehaald:\n" . implode("\n", $failed) . "\n");
        }
        $zip->close();

        return response()->download($zipPath, $this->exportName($request, 'zip'), [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    private function exportName(Request $request, string $ext): string
    {
        $range = trim(($request->input('from') ?: 'begin') . '_' . ($request->input('to') ?: date('Y-m-d')));
        return 'stripe-facturen_' . preg_replace('/[^0-9A-Za-z_-]/', '', $range) . '.' . $ext;
    }
}
