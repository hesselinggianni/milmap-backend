<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Herbruikbare O-group templates (fase-builder). De volledige fase/subfase/
 * veld-structuur staat als vrij JSON in `definition` (zelfde vrije vorm als
 * missions.ogroup). Eigendom per account (owner_id); optioneel team-breed
 * gedeeld via is_shared + team_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ogroup_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('owner_id');
            $t->string('name', 120);
            $t->string('description', 500)->nullable();
            $t->json('definition')->nullable();
            $t->boolean('is_shared')->default(false);
            $t->uuid('team_id')->nullable();
            $t->timestamps();

            $t->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
            $t->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $t->index('owner_id');
            $t->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ogroup_templates');
    }
};
