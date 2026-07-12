<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actie (Submission) — een claim van een deelnemer op een doel, die een controleur
 * in het veld goed- of afkeurt. Bij goedkeuring worden awarded_points toegekend
 * (default: de punten van het doel, door de controleur aan te passen).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exercise_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('objective_id');
            $table->uuid('mission_id');
            $table->unsignedBigInteger('user_id');
            $table->uuid('faction_id')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->text('note')->nullable();
            $table->string('photo_path')->nullable();

            $table->unsignedBigInteger('controller_id')->nullable();
            $table->integer('awarded_points')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->foreign('objective_id')
                ->references('id')->on('exercise_objectives')
                ->onDelete('cascade');

            $table->foreign('mission_id')
                ->references('id')->on('missions')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->foreign('faction_id')
                ->references('id')->on('exercise_factions')
                ->nullOnDelete();

            $table->foreign('controller_id')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['mission_id', 'status']);
            $table->index(['objective_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_submissions');
    }
};
