<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user chat-state op de conversation_user pivot voor de long-press-menu
 * acties op de chat-lijst:
 *
 *   - muted_until : tot wanneer push/notificaties uit zijn (null = niet gedempt)
 *   - favorited_at: gemarkeerd als favoriet (null = niet)
 *
 * Andere "acties" hergebruiken bestaande state:
 *   - Archiveren / Verwijderen → archived_at (al toegevoegd in trash-migratie)
 *   - Markeer ongelezen        → last_read_at terugzetten naar vóór laatste msg
 *   - Wis chat                 → messages.delete waar conversation_id = X
 *                                en sender_id = me (alleen eigen berichten weg)
 *   - Verlaten (groep)         → conversation_user-row droppen (+ system msg)
 *
 * Blokkeren is user-level (niet per-conversation), dus dat krijgt een eigen
 * tabel `user_blocks` zodat blokkades over alle 1-op-1 chats heen gelden.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('conversation_user')) {
            Schema::table('conversation_user', function (Blueprint $t) {
                if (! Schema::hasColumn('conversation_user', 'muted_until')) {
                    $t->timestamp('muted_until')->nullable()->index();
                }
                if (! Schema::hasColumn('conversation_user', 'favorited_at')) {
                    $t->timestamp('favorited_at')->nullable()->index();
                }
                // "Wis chat" verbergt berichten van vóór deze marker voor DEZE
                // gebruiker — de andere deelnemer ziet ze nog (kan ook niet
                // anders, ze hebben hun eigen sealed copy). Wis-pijl + datum.
                if (! Schema::hasColumn('conversation_user', 'cleared_at')) {
                    $t->timestamp('cleared_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable('user_blocks')) {
            Schema::create('user_blocks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('blocker_id');
                $t->unsignedBigInteger('blocked_id');
                $t->timestamps();

                $t->foreign('blocker_id')->references('id')->on('users')->cascadeOnDelete();
                $t->foreign('blocked_id')->references('id')->on('users')->cascadeOnDelete();
                $t->unique(['blocker_id', 'blocked_id']);
                $t->index('blocked_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_blocks');

        if (Schema::hasTable('conversation_user')) {
            Schema::table('conversation_user', function (Blueprint $t) {
                foreach (['favorited_at', 'muted_until'] as $col) {
                    if (Schema::hasColumn('conversation_user', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
