<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Passwordless registratie: een account kan nu bestaan zonder wachtwoord
     * (inloggen gaat dan via een e-mailcode) tot de gebruiker er zelf één
     * instelt via de link in de welkomstmail. Raw statement i.p.v. Schema's
     * ->change(): dat vereist doctrine/dbal, wat hier niet geïnstalleerd is.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY password VARCHAR(255) NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET password = '' WHERE password IS NULL");
        DB::statement('ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL');
    }
};
