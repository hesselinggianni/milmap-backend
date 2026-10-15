<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passwordless registratie: een account kan nu bestaan zonder wachtwoord
     * (inloggen gaat dan via een e-mailcode) tot de gebruiker er zelf één
     * instelt via de link in de welkomstmail.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET password = '' WHERE password IS NULL");
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
