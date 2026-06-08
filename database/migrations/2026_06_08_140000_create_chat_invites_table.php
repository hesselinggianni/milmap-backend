<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tracks "invite someone to chat" actions. Unlike `invitations` (which are
     * polymorphic, tied to a map/mission resource), a chat invite is just an
     * e-mail nudge to register. We persist it so that when the invitee creates
     * an account we can surface a "your chat invite was accepted" event on the
     * inviter's Hub activity timeline.
     */
    public function up(): void
    {
        Schema::create('chat_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Who sent the invite.
            $table->unsignedBigInteger('inviter_id');

            // The invited e-mail (always stored lower-cased).
            $table->string('email', 191)->index();

            $table->enum('status', ['pending', 'accepted'])->default('pending');

            // Filled in when a matching account registers.
            $table->unsignedBigInteger('accepted_user_id')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->timestamps();

            // One open invite per inviter per e-mail.
            $table->unique(['inviter_id', 'email'], 'uniq_chat_invite');
            $table->index(['email', 'status']);

            $table->foreign('inviter_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('accepted_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_invites');
    }
};
