<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('map_waypoints', function (Blueprint $table) {
            // Vrije key-value-velden per waypoint; welke sleutels een kaart gebruikt
            // staat in maps.settings.waypoint_fields.
            $table->json('props')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('map_waypoints', fn (Blueprint $t) => $t->dropColumn('props'));
    }
};
