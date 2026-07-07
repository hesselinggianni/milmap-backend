<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Introductie van de 7-daagse gratis proefperiode.
 *
 * Nieuwe accounts krijgen `trial_ends_at` bij registratie. Bestaande accounts
 * hebben die kolom nog leeg en zouden bij deploy dus meteen als "proef verlopen"
 * gelden — dat sluit huidige gratis gebruikers abrupt af. Deze migratie geeft
 * elk bestaand account zonder proefdatum eenmalig 7 dagen vanaf de deploy, zodat
 * de overgang zacht is. Betalende gebruikers houden hun toegang sowieso via hun
 * abonnement (hasPremiumAccess = proef OF abonnement), dus dit schaadt hen niet.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('trial_ends_at')
            ->whereNull('archived_at')
            ->update(['trial_ends_at' => now()->addDays(7)]);
    }

    public function down(): void
    {
        // Data-backfill; niet zinvol terug te draaien.
    }
};
