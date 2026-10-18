<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\ConnectMember;
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
}
