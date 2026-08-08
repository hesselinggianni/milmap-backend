<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google's onveranderlijke gebruikers-id ('sub' uit het ID-token). Zelfde
 * aanpak als apple_sub: koppelen op sub, niet op e-mail — een gekoppeld
 * bestaand account (zelfde e-mail, ooit met wachtwoord aangemaakt) krijgt
 * de sub erbij i.p.v. een tweede account te krijgen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_sub')->nullable()->unique()->after('apple_sub');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_sub');
        });
    }
};
