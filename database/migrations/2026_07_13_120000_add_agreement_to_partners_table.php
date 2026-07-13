<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partnerovereenkomst: de partner moet de voorwaarden accepteren vóór hij zijn
 * referral-link kan delen. Acceptatie wordt vastgelegd (datum/tijd + IP) en
 * daarna per e-mail bevestigd met een code/link, zodat vaststaat dat de
 * accounthouder zélf akkoord ging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->timestamp('agreement_accepted_at')->nullable()->after('approved_at');
            $table->timestamp('agreement_confirmed_at')->nullable()->after('agreement_accepted_at');
            $table->string('agreement_ip', 45)->nullable()->after('agreement_confirmed_at');
            $table->string('agreement_code', 12)->nullable()->after('agreement_ip');
            $table->timestamp('agreement_code_expires_at')->nullable()->after('agreement_code');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn([
                'agreement_accepted_at',
                'agreement_confirmed_at',
                'agreement_ip',
                'agreement_code',
                'agreement_code_expires_at',
            ]);
        });
    }
};
