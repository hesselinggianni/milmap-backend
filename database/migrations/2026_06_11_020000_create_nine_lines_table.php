<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persists submitted MEDEVAC 9-liners as a server-side entity so the Comms tab
 * can show an overview of earlier submissions, let the submitter and mission-
 * group members edit them, keep an audit log of every change, and archive old
 * ones.
 *
 * Privacy note: unlike chat messages (E2EE sealed boxes the server cannot
 * read), a 9-liner row stores its line values as PLAINTEXT JSON. That is
 * required so the server can render the overview/detail and diff edits for the
 * audit log. The chat copy delivered to the conversation stays E2EE; this is a
 * parallel, server-readable record scoped to mission-group participants.
 *
 *   conversation_id → the chat the 9-liner was delivered to (UUID, nullable:
 *                     a 9-liner may exist before/without a target chat).
 *   mission_id      → optional mission the 9-liner belongs to (UUID, nullable).
 *   user_id         → the submitter (integer FK to users).
 *   lines           → JSON array [{ n, label, value }, ...] mirroring the form.
 *   status          → 'active' | 'archived' (archived_at set when archived).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nine_lines', function (Blueprint $table) {
            $table->id();

            // conversations.id and missions.id are UUIDs (see the
            // convert_conversations_to_uuid and create_missions migrations), so
            // these FKs must be declared uuid + wired explicitly.
            $table->uuid('conversation_id')->nullable();
            $table->uuid('mission_id')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('ref')->nullable();              // serial / reference number
            $table->string('title')->default('MEDEVAC 9-liner');
            $table->string('mode')->default('noplay');      // 'exercise' | 'noplay'
            $table->boolean('exercise')->default(false);
            $table->json('lines');                          // [{ n, label, value }]

            $table->string('status')->default('active');    // 'active' | 'archived'
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->foreign('conversation_id')
                  ->references('id')->on('conversations')
                  ->nullOnDelete();
            $table->foreign('mission_id')
                  ->references('id')->on('missions')
                  ->nullOnDelete();

            $table->index(['conversation_id', 'status']);
            $table->index(['mission_id', 'status']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nine_lines');
    }
};
