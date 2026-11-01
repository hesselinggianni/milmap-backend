<?php

use App\Support\RouteGeometry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Basis voor route-ontdekking: een routekaart bewaart nu ook het gesnapte pad,
 * de sport, een hoogteprofiel, stijgen/dalen en een omhullende (bbox) waarop
 * gezocht kan worden, plus de bron en attributie (voor o.a. OpenStreetMap-data).
 *
 * Gewone kolommen (geen spatial types), zodat dit ook op SQLite en MySQL werkt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->string('sport', 12)->nullable()->after('speed');
            $table->string('difficulty', 24)->nullable()->after('sport');

            // Gesnapt pad als encoded polyline (precisie 5, ≤ 600 punten) + de
            // vingerafdruk van de waypoints waar het bij hoort.
            $table->text('path_polyline')->nullable()->after('locations');
            $table->string('path_hash', 32)->nullable()->after('path_polyline');
            $table->json('elevation_profile')->nullable()->after('path_hash');
            $table->unsignedInteger('ascent_m')->nullable()->after('elevation_profile');
            $table->unsignedInteger('descent_m')->nullable()->after('ascent_m');

            $table->double('bbox_min_lat')->nullable();
            $table->double('bbox_min_lon')->nullable();
            $table->double('bbox_max_lat')->nullable();
            $table->double('bbox_max_lon')->nullable();

            $table->string('source', 12)->default('milmap');
            $table->string('source_ref', 64)->nullable();
            $table->string('attribution', 255)->nullable();

            $table->index(['is_public', 'bbox_min_lat', 'bbox_max_lat'], 'route_maps_discover_idx');
        });

        RouteGeometry::backfillBounds();
    }

    public function down(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->dropIndex('route_maps_discover_idx');
            $table->dropColumn([
                'sport', 'difficulty', 'path_polyline', 'path_hash', 'elevation_profile',
                'ascent_m', 'descent_m',
                'bbox_min_lat', 'bbox_min_lon', 'bbox_max_lat', 'bbox_max_lon',
                'source', 'source_ref', 'attribution',
            ]);
        });
    }
};
