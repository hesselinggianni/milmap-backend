<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KAKO-training delen met andere MilMap-gebruikers (uitnodigen, bewerken) en
 * een bewerkingslog: wie heeft wat wanneer gedaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_assignment_collaborators', function (Blueprint $table) {
            $table->id();
            $table->uuid('route_assignment_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            // editor = mag bewerken, viewer = alleen kijken
            $table->string('role', 16)->default('editor');
            // pending = uitgenodigd, accepted = geaccepteerd
            $table->string('status', 16)->default('pending');
            $table->timestamps();

            $table->foreign('route_assignment_id')->references('id')->on('route_assignments')->cascadeOnDelete();
            $table->unique(['route_assignment_id', 'user_id'], 'ra_collab_unique');
            $table->index('user_id');
        });

        Schema::create('route_assignment_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('route_assignment_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Naam vastgelegd op het moment van de actie: blijft kloppen na hernoemen/verwijderen.
            $table->string('user_name', 190);
            $table->string('action', 40);
            $table->string('summary', 500);
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('route_assignment_id')->references('id')->on('route_assignments')->cascadeOnDelete();
            $table->index(['route_assignment_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_assignment_logs');
        Schema::dropIfExists('route_assignment_collaborators');
    }
};
