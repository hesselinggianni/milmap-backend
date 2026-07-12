<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deelnemer-in-een-oefening — de kern van de deelname-flow. Bevat de partijkeuze,
 * het regels-akkoord (accepted_rules_version) én een per-oefening rol-override in
 * één record.
 *
 * status: inactive → deelnemer heeft (nog) niet actief mee; active → deelt
 * locatie en doet actief mee.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exercise_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mission_id');
            $table->unsignedBigInteger('user_id');

            $table->uuid('faction_id')->nullable();

            // Overschrijft de terrein-rol voor déze oefening (null = erf terrein-rol).
            $table->enum('role_override', ['commander', 'controller', 'player'])->nullable();

            // Regels-akkoord: geaccepteerde huisregels-versie + tijdstip.
            $table->unsignedInteger('accepted_rules_version')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->enum('status', ['active', 'inactive'])->default('inactive');
            $table->timestamp('joined_at')->nullable();

            $table->timestamps();

            $table->foreign('mission_id')
                ->references('id')->on('missions')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->foreign('faction_id')
                ->references('id')->on('exercise_factions')
                ->nullOnDelete();

            $table->unique(['mission_id', 'user_id'], 'unique_exercise_participant');
            $table->index(['mission_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_participants');
    }
};
