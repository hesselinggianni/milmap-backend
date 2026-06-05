<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds mission group-chat support to the existing E2EE chat tables.
 *
 *  conversations.mission_id  → links a 'channel' conversation to a mission so
 *                              the mission Comms tab can find-or-create exactly
 *                              one group chat per mission. Nullable: ordinary
 *                              1-on-1 ('direct') conversations leave it null.
 *
 *  messages.ciphertexts      → JSON map { "<userId>": "<sealed box>" } so a
 *                              group message carries one libsodium sealed box
 *                              per recipient. Each member opens only the box
 *                              sealed to THEIR public key; the server stores
 *                              opaque ciphertext only, exactly as for 1-on-1.
 *
 *  messages.ciphertext is made nullable: group messages use `ciphertexts`
 *  instead of the single recipient/self pair used by direct messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->uuid('mission_id')->nullable()->after('name');

            $table->foreign('mission_id')
                  ->references('id')->on('missions')
                  ->nullOnDelete();

            $table->index('mission_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            // Per-recipient sealed boxes for group messages.
            $table->json('ciphertexts')->nullable()->after('ciphertext_self');
        });

        // Group messages have no single recipient ciphertext — make it nullable.
        // Raw SQL avoids the doctrine/dbal requirement of ->change().
        DB::statement('ALTER TABLE messages MODIFY ciphertext TEXT NULL');
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('ciphertexts');
        });

        // Restore NOT NULL (only safe if no group messages remain).
        DB::statement('ALTER TABLE messages MODIFY ciphertext TEXT NOT NULL');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['mission_id']);
            $table->dropIndex(['mission_id']);
            $table->dropColumn('mission_id');
        });
    }
};
