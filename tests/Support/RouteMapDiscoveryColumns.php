<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * De kolommen die RouteMap::syncDiscoveryFields() bij elke save bijwerkt.
 * Tests die hun eigen (verkleinde) `route_maps`-tabel aanmaken voegen deze toe,
 * zodat een RouteMap opgeslagen kan worden zonder de volledige migratiereeks.
 */
final class RouteMapDiscoveryColumns
{
    public static function add(Blueprint $t): void
    {
        $t->string('sport', 12)->nullable();
        $t->string('difficulty', 24)->nullable();
        $t->text('path_polyline')->nullable();
        $t->string('path_hash', 32)->nullable();
        $t->json('elevation_profile')->nullable();
        $t->unsignedInteger('ascent_m')->nullable();
        $t->unsignedInteger('descent_m')->nullable();
        $t->double('bbox_min_lat')->nullable();
        $t->double('bbox_min_lon')->nullable();
        $t->double('bbox_max_lat')->nullable();
        $t->double('bbox_max_lon')->nullable();
        $t->string('source', 12)->default('milmap');
        $t->string('source_ref', 64)->nullable();
        $t->string('attribution', 255)->nullable();
    }
}
