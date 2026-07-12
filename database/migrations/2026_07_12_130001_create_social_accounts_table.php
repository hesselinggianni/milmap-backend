<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opgeslagen platform-koppelingen (één rij per platform). Tokens worden
     * versleuteld opgeslagen (encrypted cast op het model). `board_id` is voor
     * Pinterest; `meta` voor platform-specifieke extra's (bijv. IG-user-id).
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->unique(); // pinterest | instagram | dribbble
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('board_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
