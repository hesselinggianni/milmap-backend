<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp-style emoji reactions on chat messages.
 *
 * One reaction per user per message (a new emoji replaces the previous one,
 * tapping the same emoji removes it). The emoji itself is plain metadata — it
 * is NOT message content, so storing it in clear text does not weaken the
 * end-to-end encryption of the message body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 32);
            $table->timestamps();

            // One reaction row per user per message (WhatsApp semantics).
            $table->unique(['message_id', 'user_id']);
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
