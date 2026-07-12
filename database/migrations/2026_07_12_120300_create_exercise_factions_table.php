<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partij (Faction) — een team binnen één oefening. Deelnemers kiezen hierin.
 * Kan getemplate worden vanuit het terrein (bv. standaard Rood/Blauw) en is per
 * oefening aanpasbaar door de commandant.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exercise_factions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mission_id');

            $table->string('name');
            $table->string('color')->nullable();

            // Spawn/verzamelpunt voor deze partij.
            $table->decimal('spawn_lat', 10, 8)->nullable();
            $table->decimal('spawn_lng', 11, 8)->nullable();

            $table->timestamps();

            $table->foreign('mission_id')
                ->references('id')->on('missions')
                ->onDelete('cascade');

            $table->index('mission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_factions');
    }
};
