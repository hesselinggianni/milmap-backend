<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aankomsttaken op een gemarkeerd gebied, hetzelfde model als bij waypoints
 * (add_tasks_to_map_waypoints): een JSON-lijst op de rij zelf. Bewust niet in
 * `metadata`: wie een taak afvinkte en wanneer zet de server, en metadata is
 * een vrij veld dat de client in zijn geheel overschrijft.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->json('tasks')->nullable()->after('metadata');
            // Aankomststraal in meters; null = standaard (50 m).
            $table->unsignedSmallInteger('task_radius')->nullable()->after('tasks');
        });
    }

    public function down(): void
    {
        Schema::table('reports', fn (Blueprint $t) => $t->dropColumn(['tasks', 'task_radius']));
    }
};
