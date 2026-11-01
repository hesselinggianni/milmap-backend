<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Momentopname van de cut off tijd (venster-einde, incl. extra tijd) op het moment van de
 * poging. Bij een toets is het log bewijs: "te laat" moet niet verschuiven als de instructeur
 * het venster later verlengt of inkort (zie docs/kako-app-modus.md §13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kako_app_checkins', function (Blueprint $table) {
            $table->dateTime('deadline_at', 3)->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('kako_app_checkins', function (Blueprint $table) {
            $table->dropColumn('deadline_at');
        });
    }
};
