<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elke toegangscheck op een kaart (reports/waypoints/route-kaarten/workspace-
 * lijst — alles via AuthorizesMapAccess → MapAccess) filtert missions op
 * `map->id` (het JSON-veld dat de gekoppelde kaart beschrijft). Zonder index
 * doet MySQL dat met een JSON_EXTRACT per rij — een full table scan op
 * `missions` bij ELKE kaart-toegangscheck, en dus bij zowat elke API-call die
 * iets met een kaart doet. Bij een gegroeide missions-tabel is dat de
 * geconstateerde oorzaak van 30s+ timeouts op "reports laden" (en vermoedelijk
 * de bredere traagheid/freezes elders).
 *
 * Vaste generated column (STORED, dus fysiek opgeslagen en index-baar) i.p.v.
 * de JSON-expressie zelf indexeren — MySQL kan geen functionele index direct
 * op een JSON-pad zetten vóór 8.0.13, en deze aanpak blijft leesbaar in
 * `MapAccess`. NULL zodra `map` leeg is of geen `id`-sleutel heeft — precies
 * hetzelfde gedrag als de oorspronkelijke `whereIn('map->id', …)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->string('map_ref_id', 36)
                ->nullable()
                ->storedAs("JSON_UNQUOTE(JSON_EXTRACT(`map`, '$.id'))")
                ->after('map');
        });

        Schema::table('missions', function (Blueprint $table) {
            $table->index('map_ref_id');
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropIndex(['map_ref_id']);
            $table->dropColumn('map_ref_id');
        });
    }
};
