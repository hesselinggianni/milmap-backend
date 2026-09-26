<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tot nu toe waren activiteiten (tracks) alleen aan een gebruiker gekoppeld,
 * niet aan een kaart — waardoor een track op ELKE kaart van de gebruiker
 * verscheen in plaats van alleen de kaart waarop hij is opgenomen. Nullable:
 * bestaande tracks en tracks die buiten een geopende kaart om worden
 * opgenomen (het losse /activity/record-scherm) blijven gewoon zonder
 * kaartkoppeling bestaan, ze horen dan simpelweg bij geen enkele kaart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->uuid('map_id')->nullable()->after('user_id');
            $table->foreign('map_id')->references('id')->on('maps')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['map_id']);
            $table->dropColumn('map_id');
        });
    }
};
