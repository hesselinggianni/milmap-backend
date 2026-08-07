<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opgenomen GPS-activiteiten (hardlopen/fietsen/wandelen), zoals Strava:
 * elke rij is één opname-sessie met het volledige punten-track + samengevatte
 * stats. `points` is bewust JSON (geen aparte tabel) — dit is write-once,
 * read-as-a-whole data per activiteit, geen los doorzoekbare data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('run'); // run | ride | walk
            $table->string('title')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('distance_m')->default(0);
            $table->unsignedInteger('moving_time_s')->default(0);
            $table->unsignedInteger('elapsed_time_s')->default(0);
            $table->unsignedInteger('elevation_gain_m')->default(0);
            $table->float('avg_pace_s_per_km')->nullable();
            $table->float('avg_speed_kmh')->nullable();
            $table->float('avg_power_w')->nullable();
            $table->unsignedInteger('calories')->nullable();
            $table->json('points')->nullable(); // [{lat,lon,t,ele,speed}]
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
