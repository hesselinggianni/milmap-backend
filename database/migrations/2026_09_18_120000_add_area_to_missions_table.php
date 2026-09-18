<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Werkgebied van een missie: de bounding box waarbinnen gewerkt wordt.
 *
 * De app begrenst de kaart hierop, zodat alleen dat stuk van de wereld
 * ingeladen wordt in plaats van de hele kaart.
 * Vorm: {"minLon":..,"minLat":..,"maxLon":..,"maxLat":..}
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->json('area')->nullable()->after('map');
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropColumn('area');
        });
    }
};
