<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Eenmalige inlogcodes voor gewone gebruikers — zelfde opzet als
     * admin_login_codes, maar dan voor de passwordless login-flow.
     */
    public function up(): void
    {
        Schema::create('user_login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('code')->unique();
            $table->string('ip_address');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_codes');
    }
};
