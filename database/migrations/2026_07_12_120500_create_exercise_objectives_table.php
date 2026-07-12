<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doel (Objective) — een taak binnen een oefening met een locatie- en/of tijd-eis.
 *
 * type:
 * - reach_location → binnen tijdvenster binnen radius van een punt zijn
 * - hold_location  → een punt hold_seconds lang bezet houden
 * - timed_task     → vóór window_end iets doen (deelnemer dient in)
 * - free_action    → vrije actie, volledig controleur-beoordeeld
 *
 * verification: auto (geofence) | controller (handmatig) | both (default: geofence
 * + controleur-bevestiging, tegen valsspelen).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exercise_objectives', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mission_id');

            // null = contested / voor iedereen; anders alleen voor deze partij.
            $table->uuid('faction_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();

            $table->enum('type', ['reach_location', 'hold_location', 'timed_task', 'free_action']);

            $table->decimal('target_lat', 10, 8)->nullable();
            $table->decimal('target_lng', 11, 8)->nullable();
            $table->unsignedInteger('radius_m')->nullable();
            $table->unsignedInteger('hold_seconds')->nullable();

            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();

            $table->integer('points')->default(0);

            $table->enum('verification', ['auto', 'controller', 'both'])->default('both');

            $table->string('status')->default('active');

            $table->timestamps();

            $table->foreign('mission_id')
                ->references('id')->on('missions')
                ->onDelete('cascade');

            $table->foreign('faction_id')
                ->references('id')->on('exercise_factions')
                ->nullOnDelete();

            $table->index(['mission_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_objectives');
    }
};
