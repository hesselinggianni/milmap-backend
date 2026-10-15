<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add new lifecycle states + locking to missions table
        // MariaDB/MySQL doesn't support removing enum values cleanly, so we use a
        // string column with a CHECK constraint instead of altering the enum.
        Schema::table('missions', function (Blueprint $table) {
            // approved_by and approved_at for the approval gate
            $table->unsignedBigInteger('approved_by')->nullable()->after('linked_team_id');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            // locked prevents edits after approval
            $table->boolean('locked')->default(false)->after('approved_at');
            // mission_number: human-readable identifier (e.g. "OP-2026-001")
            $table->string('mission_number', 64)->nullable()->after('name');
            // classification level
            $table->string('classification', 64)->nullable()->after('mission_number');
        });

        Schema::table('missions', function (Blueprint $table) {
            $table->enum('status', ['draft', 'planning', 'review', 'approved', 'active', 'complete', 'archived'])
                ->default('planning')->change();
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->enum('status', ['planning', 'active', 'complete'])->default('planning')->change();
        });

        Schema::table('missions', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'approved_at', 'locked', 'mission_number', 'classification']);
        });
    }
};
