<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meerdere teams per missie (commandant → eenheid → teams).
 *
 * Eén rij = één team dat aan de missie hangt. Elk team werkt zijn eigen versie
 * van de uitvoering uit (TLP-stap 3–8 + eigen order); die versie staat in
 * missions.ogroup onder `team_plans.<team_id>` zodat het samen-bewerken
 * (MissionLiveController) ongewijzigd werkt. Hier staat alleen de koppeling,
 * de kaartlaag van het team (map_id) en welke andere teams die laag mogen zien.
 *
 * missions.linked_team_id blijft bestaan (oude enkelvoudige koppeling) en telt
 * mee als team: zie Mission::teamIds().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mission_id');
            $table->uuid('team_id');
            // De kaartlaag van dit team (een eigen kaart; zie MissionTeamController).
            $table->uuid('map_id')->nullable();
            // Team-id's die de laag van dit team op de kaart mogen zien.
            $table->json('layer_share')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['mission_id', 'team_id']);
            $table->index('team_id');
            $table->index('map_id');
            $table->foreign('mission_id')->references('id')->on('missions')->cascadeOnDelete();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_teams');
    }
};
