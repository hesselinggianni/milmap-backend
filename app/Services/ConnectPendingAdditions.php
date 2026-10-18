<?php
namespace App\Services;
use App\Models\{ConnectAllowedDevice, ConnectLicense, ConnectOrder, ConnectOrderInvoice, ConnectOrganization, ConnectPendingAddition};
use Illuminate\Support\Facades\{DB, Log};
use Illuminate\Validation\ValidationException;

/**
 * Meer toevoegen dan er licenties vrij zijn: de rijen worden bewaard, de beheerder betaalt de ontbrekende
 * seats en na de betaalde factuur (invoice.paid) worden alle rijen in één keer toegevoegd.
 */
class ConnectPendingAdditions
{
    public function create(ConnectOrganization $org, ?int $userId, string $kind, array $rows): ConnectPendingAddition {
        $rows = $kind === 'members' ? $this->memberRows($rows) : $this->deviceRows($rows);
        $new = count($this->newRows($org, $kind, $rows));
        abort_if($new === 0, 422, $kind === 'members' ? 'Deze gebruikers zijn al actief of uitgenodigd.' : 'Deze apparaten staan al in de lijst.');
        $free = max(0, $org->seat_limit - $org->usedLicenses());
        abort_if($new <= $free, 422, 'Er zijn genoeg licenties vrij; voeg ze direct toe.');
        return DB::transaction(function () use ($org, $userId, $kind, $rows, $new, $free) {
            // Eén openstaande aanvraag zonder bestelling per organisatie: een nieuwe vervangt de vorige.
            ConnectPendingAddition::where('organization_id', $org->id)->where('status', 'pending')->whereNull('order_id')->update(['status' => 'cancelled', 'rows' => null]);
            $pending = ConnectPendingAddition::create(['organization_id' => $org->id, 'created_by' => $userId, 'kind' => $kind, 'rows' => $rows,
                'row_count' => count($rows), 'seats_needed' => $new - $free, 'status' => 'pending']);
            app(ConnectManagement::class)->audit($org->id, 'pending_addition.created', 'ConnectPendingAddition', $pending->id, ['kind' => $kind, 'rows' => count($rows), 'seats_needed' => $pending->seats_needed]);
            return $pending;
        });
    }

    /** Bij het bestellen: de aanvraag hoort bij deze organisatie, staat nog open en de bestelling dekt het tekort. */
    public function assertAttachable(ConnectOrganization $org, string $id, int $seats): ConnectPendingAddition {
        $pending = ConnectPendingAddition::where('organization_id', $org->id)->whereKey($id)->lockForUpdate()->first();
        abort_unless($pending && $pending->status === 'pending', 422, 'Deze toevoeging is al verwerkt of geannuleerd. Ga terug naar Connect en probeer het opnieuw.');
        abort_if($seats < $pending->seats_needed, 422, "Bestel minimaal {$pending->seats_needed} seats om alles toe te voegen.");
        return $pending;
    }

    /** Na een betaalde factuur van de bestelling: alle openstaande toevoegingen uitvoeren. */
    public function processForOrder(ConnectOrder $order): void {
        foreach (ConnectPendingAddition::where('order_id', $order->id)->where('status', 'pending')->get() as $pending) $this->process($pending);
    }

    /**
     * Status opvragen; staat een betaalde aanvraag nog open (webhook mislukt of vastgelopen), dan nu verwerken.
     */
    public function reconcile(ConnectPendingAddition $pending): ConnectPendingAddition {
        $stuck = $pending->status === 'processing' && $pending->updated_at < now()->subMinutes(10);
        if (($pending->status === 'pending' || $stuck) && $this->paid($pending)) return $this->process($pending, $stuck);
        return $pending;
    }

    public function retry(ConnectPendingAddition $pending): ConnectPendingAddition {
        abort_unless($pending->status === 'failed', 422, 'Alleen een mislukte toevoeging kan opnieuw.');
        abort_unless($this->paid($pending), 422, 'De betaling is nog niet binnen.');
        return $this->process($pending, true);
    }

    public function cancel(ConnectPendingAddition $pending): ConnectPendingAddition {
        abort_unless(in_array($pending->status, ['pending', 'failed'], true), 422, 'Deze toevoeging is al verwerkt.');
        // Al betaald: de seats blijven, alleen het automatisch toevoegen vervalt.
        $pending->update(['status' => 'cancelled', 'rows' => null]);
        app(ConnectManagement::class)->audit($pending->organization_id, 'pending_addition.cancelled', 'ConnectPendingAddition', $pending->id);
        return $pending;
    }

    public function process(ConnectPendingAddition $pending, bool $retry = false): ConnectPendingAddition {
        // Claimen in een korte transactie: dubbele webhooks en de status-poll voeren het maar één keer uit.
        $claimed = DB::transaction(function () use ($pending, $retry) {
            $locked = ConnectPendingAddition::whereKey($pending->id)->lockForUpdate()->firstOrFail();
            $allowed = $retry ? ['pending', 'failed', 'processing'] : ['pending'];
            if (!in_array($locked->status, $allowed, true) || !$this->paid($locked)) return null;
            $locked->update(['status' => 'processing']);
            return $locked;
        });
        if (!$claimed) return $pending->fresh();
        $org = ConnectOrganization::findOrFail($claimed->organization_id);
        try {
            $result = $claimed->kind === 'members' ? $this->addMembers($org, $claimed->rows ?? []) : $this->addDevices($org, $claimed->rows ?? []);
            $claimed->update(['status' => 'completed', 'rows' => null, 'result' => $result, 'processed_at' => now()]);
            app(ConnectManagement::class)->audit($org->id, 'pending_addition.completed', 'ConnectPendingAddition', $claimed->id, $result);
        } catch (ValidationException $e) {
            $claimed->update(['status' => 'failed', 'result' => ['message' => collect($e->errors())->flatten()->first() ?: 'Toevoegen is niet gelukt.']]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $claimed->update(['status' => 'failed', 'result' => ['message' => $e->getMessage() ?: 'Toevoegen is niet gelukt.']]);
        } catch (\Throwable $e) {
            Log::error('Connect: toevoegen na betaling mislukt', ['pending_addition' => $claimed->id, 'error' => $e->getMessage()]);
            $claimed->update(['status' => 'failed', 'result' => ['message' => 'Toevoegen is niet gelukt. Probeer het opnieuw.']]);
        }
        return $claimed->fresh();
    }

    /** Voor Connect: openstaande en recent afgeronde toevoegingen. */
    public function recent(ConnectOrganization $org): array {
        return ConnectPendingAddition::where('organization_id', $org->id)
            ->where(fn ($q) => $q->whereIn('status', ['pending', 'processing', 'failed'])->orWhere(fn ($q) => $q->where('status', 'completed')->where('processed_at', '>', now()->subDay())))
            ->with('order')->latest()->limit(10)->get()->map(fn ($p) => $this->present($p))->all();
    }

    public function present(ConnectPendingAddition $p): array {
        $order = $p->order_id ? ($p->relationLoaded('order') ? $p->order : $p->order()->first()) : null;
        return ['id' => $p->id, 'kind' => $p->kind, 'row_count' => $p->row_count, 'seats_needed' => $p->seats_needed, 'status' => $p->status,
            'result' => $p->result, 'order_id' => $p->order_id, 'order_status' => $order?->status,
            'checkout_url' => $order && $order->status === 'awaiting_payment' && $order->payment_method === 'direct' ? $order->checkout_url : null,
            'created_at' => $p->created_at, 'processed_at' => $p->processed_at];
    }

    private function paid(ConnectPendingAddition $p): bool {
        if (!$p->order_id) return false;
        // Tijdens de proefperiode is de bestelling gratis: de licenties staan er direct.
        if (ConnectOrder::whereKey($p->order_id)->where('payment_method', 'trial')->exists()) return true;
        // invoice.finalized may already contain status=paid before invoice.paid grants the seats.
        // Polling must wait for the verified payment and capacity transaction to finish.
        $references = ConnectOrderInvoice::where('order_id', $p->order_id)->where('status', 'paid')->pluck('stripe_invoice_id')
            ->map(fn ($id) => 'stripe-invoice:'.$id);
        return ConnectLicense::where('organization_id', $p->organization_id)->whereIn('source_reference', $references)->exists();
    }

    private function memberRows(array $rows): array {
        $out = []; $seen = [];
        foreach (array_values($rows) as $i => $row) {
            $email = mb_strtolower(trim($row['email']));
            if (isset($seen[$email])) throw ValidationException::withMessages(["members.$i.email" => 'Dubbel e-mailadres in deze import.']);
            $seen[$email] = true;
            $out[] = ['first_name' => trim($row['first_name']), 'last_name' => trim($row['last_name']), 'email' => $email,
                'tags' => array_values(array_unique($row['tags'] ?? [])), 'role' => $row['role'] ?? 'member'];
        }
        return $out;
    }

    private function deviceRows(array $rows): array {
        return collect($rows)->map(fn ($d) => ['name' => trim($d['name']), 'udid' => strtoupper(trim($d['udid'])), 'platform' => $d['platform'] ?? 'ios'])->unique('udid')->values()->all();
    }

    /** Rijen die nog geen licentie hebben (gebruiker niet actief/uitgenodigd, apparaat niet in de lijst). */
    private function newRows(ConnectOrganization $org, string $kind, array $rows): array {
        if ($kind === 'devices') {
            $known = ConnectAllowedDevice::where('organization_id', $org->id)->pluck('udid')->map(fn ($u) => strtoupper($u))->flip();
            return array_values(array_filter($rows, fn ($d) => !$known->has($d['udid'])));
        }
        $taken = $org->members()->where(fn ($q) => $q->where('status', 'active')->orWhere(fn ($q) => $q->where('status', 'pending')->where('expires_at', '>', now())))
            ->pluck('email')->map(fn ($e) => mb_strtolower($e))->flip();
        return array_values(array_filter($rows, fn ($m) => !$taken->has($m['email'])));
    }

    private function addMembers(ConnectOrganization $org, array $rows): array {
        // Wie intussen al actief of uitgenodigd is, slaan we over in plaats van de hele import te weigeren.
        $new = $this->newRows($org, 'members', $rows);
        $result = $new ? app(ConnectRoster::class)->invite($org, $new) : ['invited' => 0, 'mail_failed_ids' => []];
        return ['added' => $result['invited'], 'skipped' => count($rows) - count($new), 'mail_failed' => count($result['mail_failed_ids'])];
    }

    private function addDevices(ConnectOrganization $organization, array $rows): array {
        return DB::transaction(function () use ($organization, $rows) {
            $org = ConnectOrganization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $new = $this->newRows($org, 'devices', $rows);
            $free = max(0, $org->seat_limit - $org->usedLicenses());
            abort_if(count($new) > $free, 422, "Er zijn {$free} licenties vrij; er moeten ".count($new).' apparaten bij.');
            foreach ($rows as $d) ConnectAllowedDevice::updateOrCreate(['organization_id' => $org->id, 'udid' => $d['udid']], ['name' => $d['name'], 'platform' => $d['platform']]);
            return ['added' => count($new), 'skipped' => count($rows) - count($new)];
        });
    }
}
