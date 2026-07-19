<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beantwoorden + vastzetten in de chat.
 *
 *  messages.reply_to_id → verwijzing naar het bericht waarop dit een antwoord
 *                         is (quote-blok in de bubbel). FK met nullOnDelete:
 *                         wordt het origineel ingetrokken ("verzenden ongedaan
 *                         maken"), dan valt de quote terug op "verwijderd".
 *  messages.pinned_at   → wanneer dit bericht in het gesprek is vastgezet
 *                         (banner bovenin de chat). NULL = niet vastgezet.
 *  messages.pinned_by   → wie het vastzette (metadata voor de banner).
 *
 * Beide zijn pure metadata — de E2EE-inhoud blijft verzegeld.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('reply_to_id')->nullable()->after('sender_id');
            $table->timestamp('pinned_at')->nullable()->after('encryption');
            $table->unsignedBigInteger('pinned_by')->nullable()->after('pinned_at');

            $table->foreign('reply_to_id')
                  ->references('id')->on('messages')
                  ->nullOnDelete();

            // Banner-query: alle vastgezette berichten van één gesprek.
            $table->index(['conversation_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['reply_to_id']);
            $table->dropIndex(['conversation_id', 'pinned_at']);
            $table->dropColumn(['reply_to_id', 'pinned_at', 'pinned_by']);
        });
    }
};
