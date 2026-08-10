<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kortlevende, eenmalig-inwisselbare PKCE-state voor de Garmin OAuth-redirect,
 * zelfde vorm als auth_handoff_tokens. `platform` bepaalt in de callback naar
 * welk scherm (web-redirect of milmap://garmin/callback) wordt teruggestuurd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garmin_oauth_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('state', 64)->unique();
            $table->string('code_verifier', 128);
            $table->string('platform', 16);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garmin_oauth_states');
    }
};
