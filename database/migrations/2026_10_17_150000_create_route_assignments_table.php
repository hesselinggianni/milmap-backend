<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kaart-en-kompasopdracht: één opdracht bundelt een set gegenereerde
 * routekaarten (route 1a, 2b, …), de deelnemers die ze lopen en de planning
 * (tempo per km per type/dag-nacht, starttijden, uiterlijk-binnen).
 *
 * De routes zelf blijven gewone route_maps — zo werken routekaarttabel,
 * print, presentatie en flyover er zonder extra werk op. De koppeling loopt
 * via route_maps.route_assignment_id; route_code is het label ("1a").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('map_id');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('title');
            // training | exam
            $table->string('type', 16)->default('training');

            // Tempo, generator-instellingen en planning (dag- en nachtloop).
            $table->json('settings')->nullable();
            // [{ id, name, route_code }]
            $table->json('participants')->nullable();

            $table->timestamps();

            $table->foreign('map_id')->references('id')->on('maps')->cascadeOnDelete();
            $table->index('map_id');
        });

        Schema::table('route_maps', function (Blueprint $table) {
            $table->uuid('route_assignment_id')->nullable()->after('mission_id');
            $table->string('route_code', 16)->nullable()->after('route_assignment_id');
            $table->foreign('route_assignment_id')
                ->references('id')
                ->on('route_assignments')
                ->nullOnDelete();
            $table->index('route_assignment_id');
        });
    }

    public function down(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->dropForeign(['route_assignment_id']);
            $table->dropIndex(['route_assignment_id']);
            $table->dropColumn(['route_assignment_id', 'route_code']);
        });

        Schema::dropIfExists('route_assignments');
    }
};
