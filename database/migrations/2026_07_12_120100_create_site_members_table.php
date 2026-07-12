<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terrein-rollen (de standaard). Bepaalt wie op een terrein Commandant of
 * Controleur is; deze rol geldt standaard in elke oefening op dat terrein en is
 * per oefening te overschrijven (exercise_participants.role_override).
 *
 * - commander  → beheert alles (terrein + oefeningen)
 * - controller → keurt acties goed/af in het veld
 * - player     → gewone deelnemer
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->unsignedBigInteger('user_id');

            $table->enum('role', ['commander', 'controller', 'player'])->default('player');

            $table->unsignedBigInteger('added_by')->nullable();

            $table->timestamps();

            $table->foreign('site_id')
                ->references('id')->on('exercise_sites')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->foreign('added_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->unique(['site_id', 'user_id'], 'unique_site_user');
            $table->index(['site_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_members');
    }
};
