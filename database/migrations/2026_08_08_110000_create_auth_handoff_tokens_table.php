<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kortlevende, eenmalig-inwisselbare token om de ingelogde app-sessie over te
// dragen naar de systeembrowser (externe checkout, zie externalBilling.js) —
// de webview deelt geen cookies/localStorage met Safari/Chrome, dus een
// gewoon "open URL" logt de gebruiker daar niet in.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_handoff_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_handoff_tokens');
    }
};
