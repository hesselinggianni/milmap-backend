<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apple's onveranderlijke gebruikers-id ('sub' uit het identity token).
 *
 * Bewust hierop koppelen en niet op e-mail: Apple-gebruikers mogen hun adres
 * verbergen (dan krijg je een doorstuuradres) en Apple geeft e-mail en naam
 * ALLEEN mee bij de allereerste autorisatie. Op e-mail koppelen levert bij de
 * tweede login een tweede account op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apple_sub')->nullable()->unique()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('apple_sub');
        });
    }
};
