<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_briefings', function (Blueprint $table) {
            $table->id();
            $table->string('mission_id', 36)->unique();
            $table->foreign('mission_id')->references('id')->on('missions')->onDelete('cascade');

            // ── Paragraph 1: Situation ───────────────────────────────
            $table->text('enemy_forces')->nullable();
            $table->text('friendly_forces')->nullable();
            $table->text('civilian_considerations')->nullable();
            $table->text('ground_conditions')->nullable();

            // ── Paragraph 2: Mission ────────────────────────────────
            // commander_intent is separate from mission statement (ogroup.mission)
            $table->text('commander_intent')->nullable();

            // ── Paragraph 3: Execution ──────────────────────────────
            $table->text('action_on_procedures')->nullable();
            // H-hour and phased timeline as JSON: [{label, offset_minutes, description}]
            $table->json('timeline')->nullable();

            // ── Paragraph 4: CSS ────────────────────────────────────
            $table->text('casevac')->nullable();
            $table->text('medevac')->nullable();
            // PACE Plan: Primary/Alternate/Contingency/Emergency comms method
            $table->json('pace_plan')->nullable();

            // ── Paragraph 5: Command & Signals ──────────────────────
            // Stored in mission_radio_channels table (separate)

            // ── Extra briefing meta ─────────────────────────────────
            // Weather: {wind_speed, wind_dir, visibility, precipitation, temperature}
            $table->json('weather')->nullable();
            // Light conditions: {sunrise, sunset, moon_phase, moon_illumination, nautical_twilight}
            $table->json('light_conditions')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_briefings');
    }
};
