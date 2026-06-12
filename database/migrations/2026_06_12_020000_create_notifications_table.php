<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app meldingen — een lichte notificaties-tabel die de Hub-timeline en
 * /meldingen voedt. Geen Laravel-notifications-stack: we slaan zelf het
 * minimum op (type, titel, body, json-payload, gelezen-status) zodat we
 * controle hebben over de listing-query.
 *
 * Type-strings die we vandaag gebruiken:
 *   - "mission.completed"  → "Missie afgerond, vul de debrief in"
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('type', 64);
            $t->string('title', 255);
            $t->string('body', 500)->nullable();
            // payload kan bv. { mission_id, action_url, … } bevatten zodat de
            // FE 1-klik naar de juiste vervolgactie kan navigeren.
            $t->json('payload')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();

            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $t->index(['user_id', 'read_at']);
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
