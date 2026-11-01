<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('map_waypoints', function (Blueprint $table) {
            // Aankomsttaken (à la Apple Reminders): [{ id, title, required, done,
            // done_at, done_by, done_by_name }]. Bewust een JSON-lijst op het
            // waypoint zelf en geen aparte tabel: de taken reizen zo mee met de
            // bestaande sync, de offline-outbox en de realtime-broadcast.
            $table->json('tasks')->nullable()->after('props');
            // Aankomststraal in meters; null = standaard (50 m).
            $table->unsignedSmallInteger('task_radius')->nullable()->after('tasks');
        });
    }

    public function down(): void
    {
        Schema::table('map_waypoints', fn (Blueprint $t) => $t->dropColumn(['tasks', 'task_radius']));
    }
};
