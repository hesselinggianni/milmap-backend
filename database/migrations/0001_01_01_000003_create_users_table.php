<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        // Controleer of de 'users' tabel al bestaat en maak deze aan indien nodig
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
               
                $table->string('language')->nullable(); // Toegevoegd veld voor taal
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                 $table->rememberToken();
                $table->timestamps();
            });
        }

        // Controleer of de 'password_reset_tokens' tabel al bestaat en maak deze aan indien nodig
        if (!Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        // Controleer of de 'sessions' tabel al bestaat en maak deze aan indien nodig
        if (!Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('first_name');
                $table->string('last_name');
                $table->string('language');
                $table->string('timezone'); // Toegevoegd veld voor tijdzone
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable(); // User agent als tekst
                $table->longText('payload'); // Lange tekst voor payload
                $table->integer('last_activity')->index(); // Index voor snellere zoekopdrachten op activiteit
                $table->timestamps(); // timestamps toevoegen
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
