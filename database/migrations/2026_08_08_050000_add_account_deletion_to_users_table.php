<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account verwijderen met bedenktijd.
 *
 * De app had de hele flow al (code per mail → wachtwoord → woord overtypen),
 * maar er was niets om 'm aan te koppelen. Verwijderen gaat niet direct: de
 * gebruiker krijgt 90 dagen bedenktijd waarin inloggen het verzoek annuleert.
 * Dat vangt zowel spijt als een gekaapte sessie af.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable()->after('archived_at');
            $table->timestamp('deletion_purge_at')->nullable()->after('deletion_requested_at');
            // Hash, geen leesbare code: wie de databank inziet mag geen
            // account kunnen laten verwijderen.
            $table->string('deletion_code_hash')->nullable()->after('deletion_purge_at');
            $table->timestamp('deletion_code_expires_at')->nullable()->after('deletion_code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'deletion_requested_at', 'deletion_purge_at',
                'deletion_code_hash', 'deletion_code_expires_at',
            ]);
        });
    }
};
