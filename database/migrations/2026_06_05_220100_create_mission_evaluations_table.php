<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hot-debrief evaluations — one row per participant per mission.
 * Participants fill this in during or after a mission.
 * Admins/owners see all submissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mission_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->tinyInteger('rating')->nullable();          // 1–5 stars
            $table->enum('goal_achieved', ['yes', 'partial', 'no'])->nullable();
            $table->text('went_well')->nullable();
            $table->text('went_wrong')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            $table->foreign('mission_id')
                  ->references('id')->on('missions')
                  ->cascadeOnDelete();

            $table->unique(['mission_id', 'user_id']); // one eval per participant
            $table->index('mission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_evaluations');
    }
};
