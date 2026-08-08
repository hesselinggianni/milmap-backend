<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Militair waypoint-type (startpunt/eindpunt/rally point/ERV/HLZ/CCP/OP/
 * checkpoint) — nodig voor de "bewaar dit waypoint als ..."-Siri-opdrachten.
 * Bewust een vrij string-veld (geen enum-kolom): dezelfde reden als `icon`
 * hierboven al vrij is — nieuwe types moeten zonder migratie toe te voegen zijn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_waypoints', function (Blueprint $table) {
            $table->string('type', 30)->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('map_waypoints', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
