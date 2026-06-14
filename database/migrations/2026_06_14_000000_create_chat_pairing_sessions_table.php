<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * QR-gebaseerde apparaatkoppeling voor de end-to-end versleutelde chat
     * (WhatsApp-Web-patroon). Een vergrendeld apparaat (zonder lokale privé-
     * sleutel) toont een QR met een EPHEMERAL publieke sleutel. Een reeds
     * ontgrendeld apparaat scant de QR, *sealt* de chat-privésleutel naar die
     * ephemeral key en levert de opaque ciphertext hier af.
     *
     * Zero-knowledge: de server slaat alleen de sealed ciphertext op. Zonder de
     * ephemeral PRIVÉ-sleutel (die het tonende apparaat in geheugen houdt) kan
     * de server niets ontsleutelen. Rijen zijn eenmalig en kortlevend.
     */
    public function up(): void
    {
        Schema::create('chat_pairing_sessions', function (Blueprint $table) {
            // Willekeurige sessie-token; ook de identifier in de QR-payload.
            $table->string('id')->primary();

            // De koppeling is altijd binnen één account: alleen de eigenaar mag
            // claimen en uitlezen. Verwijdert mee met het account.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Ephemeral X25519 publieke sleutel (base64) van het tonende
            // apparaat — de sealed payload is hiernaartoe versleuteld.
            $table->string('ephemeral_public_key');

            // Door het scannende apparaat geleverde sealed box (base64) met de
            // chat-privésleutel erin. Null zolang nog niet gescand.
            $table->text('sealed_payload')->nullable();

            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_pairing_sessions');
    }
};
