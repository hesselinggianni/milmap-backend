<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds linked_team_id (UUID FK → teams.id) to the missions table.
 *
 * When a team is linked and the mission status is 'active', all members of
 * that team automatically get viewer access — no invitation acceptance needed.
 * The server enforces this in Mission::roleFor().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->uuid('linked_team_id')->nullable()->after('owner_id');

            $table->foreign('linked_team_id')
                  ->references('id')->on('teams')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropForeign(['linked_team_id']);
            $table->dropColumn('linked_team_id');
        });
    }
};
