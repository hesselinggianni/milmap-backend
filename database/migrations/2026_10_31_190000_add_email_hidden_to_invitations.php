<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Een uitnodiging voor iemand die de uitnodiger alleen op gebruikersnaam heeft
     * gevonden: het adres blijft in de uitnodiging (de mail moet er heen), maar de
     * uitnodiger krijgt het niet te zien (uitnodigingslijst, tijdlijn, API-antwoord).
     */
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->boolean('email_hidden')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn('email_hidden');
        });
    }
};
