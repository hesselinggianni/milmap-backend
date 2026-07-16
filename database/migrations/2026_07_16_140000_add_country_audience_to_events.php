<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics-uitbreiding: land van herkomst (privacy-vriendelijk, ISO-2, afgeleid
 * uit het IP zonder het IP op te slaan) + of het om een ingelogde gebruiker of
 * gast gaat. Platform (site/web/ios/android) zat al op app_events; site_events
 * geldt impliciet als 'site' + altijd gast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_events', function (Blueprint $table) {
            if (! Schema::hasColumn('app_events', 'country')) {
                $table->char('country', 2)->nullable()->after('device')->index();
            }
            if (! Schema::hasColumn('app_events', 'is_authed')) {
                // null = onbekend (oude events), true = ingelogd, false = gast
                $table->boolean('is_authed')->nullable()->after('country');
            }
        });

        Schema::table('site_events', function (Blueprint $table) {
            if (! Schema::hasColumn('site_events', 'country')) {
                $table->char('country', 2)->nullable()->after('device')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_events', function (Blueprint $table) {
            if (Schema::hasColumn('app_events', 'country'))   $table->dropColumn('country');
            if (Schema::hasColumn('app_events', 'is_authed')) $table->dropColumn('is_authed');
        });
        Schema::table('site_events', function (Blueprint $table) {
            if (Schema::hasColumn('site_events', 'country')) $table->dropColumn('country');
        });
    }
};
