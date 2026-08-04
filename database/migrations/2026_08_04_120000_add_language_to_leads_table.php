<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taal van de bezoeker op het moment dat de lead werd vastgelegd (browsertaal,
 * genormaliseerd naar een ondersteunde site-taal) — zodat lead-nurture-mails
 * niet meer altijd in het Nederlands verstuurd worden. Leeg = onbekend, dan
 * valt LeadNurtureService terug op Engels i.p.v. Nederlands.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->string('language', 5)->nullable()->after('utm_source');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $t) {
            $t->dropColumn('language');
        });
    }
};
