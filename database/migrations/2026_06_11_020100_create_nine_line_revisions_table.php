<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for 9-liners. One row per action so the detail page can show
 * "what changed, by whom, when".
 *
 *   action  → 'created' | 'updated' | 'archived' | 'unarchived'
 *   changes → JSON diff for 'updated': { "<fieldKey>": { from, to }, ... }.
 *             Null for created / archived / unarchived.
 *   user_id → who performed the action (nullable so the log survives if the
 *             user is later deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nine_line_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nine_line_id')->constrained('nine_lines')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->index(['nine_line_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nine_line_revisions');
    }
};
