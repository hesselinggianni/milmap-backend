<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vrije notitie bij een waypoint — nodig voor de "voeg notitie toe aan
 * waypoint"-Siri-opdracht (Fase 6, operationeel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_waypoints', function (Blueprint $table) {
            $table->text('note')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('map_waypoints', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
