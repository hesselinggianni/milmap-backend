<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geheim per-admin token voor de iCal-agenda-abonnee-feed. De feed-URL bevat
 * dit token als enige geheim (de iPhone-Agenda-app kan geen Authorization-
 * header meesturen), dus het is lang en willekeurig — precies zoals Google/
 * Fastmail hun privé-iCal-URL's beveiligen. Lazily gevuld bij het eerste
 * ophalen van de abonneer-link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('agenda_ical_token', 64)->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('agenda_ical_token');
        });
    }
};
