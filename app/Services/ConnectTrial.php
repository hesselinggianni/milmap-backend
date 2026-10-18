<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\ConnectMember;
use App\Models\ConnectOrder;
use App\Models\ConnectOrganization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Zelfbediening vanuit connect.milmap.nl: een ingelogde gebruiker zonder organisatie start zelf een
 * proefperiode. Dat maakt dezelfde structuur als de admin-knop "Connect-organisatie aanmaken" met
 * trial_days: organisatie, eigenaar-lidmaatschap en een Proeflicentie (is_trial) die direct actief is,
 * zodat de gebruiker meteen gebruikers en apparaten kan toevoegen.
 */
class ConnectTrial
{
    public const DAYS = 30;
    public const SEATS = 25;
    /** Meer licenties dan dit zijn tijdens de proefperiode niet gratis te bestellen (neem contact op). */
    public const MAX_SEATS = 500;

    public function start(User $user, string $name): ConnectOrganization
    {
        $organization = DB::transaction(function () use ($user, $name) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            // Eén proefperiode per account: ook een verlopen proeforganisatie telt mee.
            abort_if(ConnectMember::where('user_id', $user->id)->where('role', 'owner')->exists(), 422, 'Je hebt al een Connect-organisatie of proefperiode.');
            $organization = ConnectOrganization::create([
                'name' => $name, 'contact_name' => trim($user->first_name.' '.$user->last_name) ?: null, 'contact_email' => mb_strtolower($user->email),
                'seat_limit' => self::SEATS, 'monthly_price_cents' => 0, 'monthly_price_per_seat_cents' => 0, 'currency' => 'EUR',
                'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addDays(self::DAYS),
            ]);
            ConnectMember::create([
                'organization_id' => $organization->id, 'user_id' => $user->id, 'first_name' => $user->first_name ?: 'Beheerder',
                'last_name' => $user->last_name ?: '', 'email' => mb_strtolower($user->email), 'role' => 'owner', 'status' => 'active', 'tags' => [], 'accepted_at' => now(),
            ]);
            app(ConnectLicensing::class)->preserveExisting($organization);
            $organization->licenses()->update(['is_trial' => true, 'name' => 'Proeflicentie']);
            return $organization->load('licenses');
        });

        AppNotification::notifyAdmins('admin.connect', 'Nieuwe Connect-proefperiode', $organization->name.' — '.$user->email, ['action_url' => '/users/'.$user->id]);

        return $organization;
    }

    /**
     * Tijdens de proefperiode kost bestellen niets: de licenties gaan direct op de proeflicentie en wat er in
     * Connect op wachtte (pending addition) wordt meteen toegevoegd. Betalen gebeurt pas na de proefperiode, in de
     * keuzemuur. De bestelling wordt wel bewaard (payment_method 'trial') voor de administratie en als idempotentiesleutel.
     */
    public function order(ConnectOrganization $organization, int $actorId, array $data): ConnectOrder
    {
        $order = DB::transaction(function () use ($organization, $actorId, $data) {
            $locked = ConnectOrganization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $existing = ConnectOrder::where('organization_id', $locked->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->payment_method === 'trial' && $existing->seats === (int) $data['seats'], 409, 'Deze bestelreferentie hoort bij een andere bestelling.');
                return $existing;
            }
            abort_unless($locked->onTrial(), 409, 'De proefperiode is niet (meer) actief. Ververs de pagina.');
            $seats = (int) $data['seats'];
            $total = $locked->seat_limit + $seats;
            abort_if($total > self::MAX_SEATS, 422, 'Tijdens de proefperiode kun je maximaal '.self::MAX_SEATS.' licenties bestellen. Neem contact op met MilMap voor meer.');
            $pending = ! empty($data['pending_addition_id']) ? app(ConnectPendingAdditions::class)->assertAttachable($locked, $data['pending_addition_id'], $seats) : null;
            $locked->licenses()->active()->where('is_trial', true)->firstOrFail()->increment('seat_count', $seats);
            $order = ConnectOrder::create(['organization_id' => $locked->id, 'ordered_by' => $actorId, 'request_id' => $data['request_id'], 'seats' => $seats, 'term_months' => 1,
                'payment_method' => 'trial', 'status' => 'trial', 'unit_monthly_cents' => 0, 'total_cents' => 0, 'vat_percent' => 0, 'vat_cents' => 0, 'total_incl_cents' => 0,
                'currency' => $locked->currency ?: 'EUR', 'billing_details' => [], 'auto_renew' => false]);
            $pending?->update(['order_id' => $order->id]);
            app(ConnectManagement::class)->audit($locked->id, 'order.trial', 'ConnectOrder', $order->id, ['seats' => $seats, 'total_seats' => $total]);
            return $order;
        });
        // Seats zijn er nu: wat in Connect op deze bestelling wachtte, wordt toegevoegd (mislukken blokkeert de bestelling niet).
        try { app(ConnectPendingAdditions::class)->processForOrder($order); } catch (\Throwable $e) { report($e); }
        return $order->fresh();
    }
}
