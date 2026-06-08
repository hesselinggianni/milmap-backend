<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Chat connection requests between two existing accounts.
     *
     * When you look up someone who already has a MilMap account and want to
     * chat, we DON'T open a conversation straight away — we send them a request
     * they must accept first. Only on acceptance is a direct conversation
     * created. A declined request means the requester cannot chat with them.
     */
    public function up(): void
    {
        Schema::create('chat_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('requester_id');
            $table->unsignedBigInteger('recipient_id');

            $table->enum('status', ['pending', 'accepted', 'declined'])->default('pending');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            // One open request per direction.
            $table->unique(['requester_id', 'recipient_id'], 'uniq_chat_request');
            $table->index(['recipient_id', 'status']);

            $table->foreign('requester_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('recipient_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_requests');
    }
};
