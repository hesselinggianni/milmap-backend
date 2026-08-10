<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gekoppeld Garmin Connect-account per gebruiker (OAuth 2.0 + PKCE). Tokens
 * worden versleuteld opgeslagen (encrypted cast op het model), net als
 * social_accounts. `devices` is een cache van Garmin's devicelijst voor deze
 * gebruiker (model/id), puur informatief — geen bron van waarheid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garmin_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('garmin_user_id')->unique();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at')->useCurrent();
            $table->string('scope')->nullable();
            $table->json('devices')->nullable();
            $table->timestamp('connected_at')->useCurrent();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garmin_accounts');
    }
};
