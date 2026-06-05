<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();

            // E2EE: ciphertext is a libsodium sealed box (crypto_box_seal).
            // For 1-on-1 we store two sealed copies so BOTH parties can read
            // history on any device — one sealed to the recipient's public key,
            // one sealed to the sender's own public key. The server can decrypt
            // neither; it only stores opaque ciphertext.
            $table->text('ciphertext');        // sealed to the recipient
            $table->text('ciphertext_self')->nullable(); // sealed to the sender
            $table->enum('encryption', ['sealed', 'none'])->default('sealed');

            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
