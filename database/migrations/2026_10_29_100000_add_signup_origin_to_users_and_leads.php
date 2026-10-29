<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Herkomst van een aanmelding vastleggen, voor het periodieke admin-overzicht
 * (AdminDigestService): via welke pagina/URL kwam iemand binnen, van welke
 * externe site, en uit welk land.
 *
 * Tot nu toe stond dit alleen in de realtime admin-mail en werd het niet
 * bewaard — een dag-/weekoverzicht achteraf kon het dus niet reconstrueren.
 * Bestaande rijen blijven leeg; alleen het land van oude leads is nog af te
 * leiden (uit het al bewaarde IP, zie AdminDigestService::leadCountry()).
 *
 * Alleen een ISO-2 landcode — nooit het IP van een gebruiker erbij bewaren
 * (zie App\Support\GeoIp).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('signup_source_url', 500)->nullable()->after('referred_by_id');
            $t->string('signup_referrer', 500)->nullable()->after('signup_source_url');
            $t->string('signup_country', 2)->nullable()->after('signup_referrer');
        });

        Schema::table('leads', function (Blueprint $t) {
            $t->string('source_url', 500)->nullable()->after('utm_source');
            $t->string('referrer', 500)->nullable()->after('source_url');
            $t->string('country', 2)->nullable()->after('referrer');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['signup_source_url', 'signup_referrer', 'signup_country']);
        });

        Schema::table('leads', function (Blueprint $t) {
            $t->dropColumn(['source_url', 'referrer', 'country']);
        });
    }
};
