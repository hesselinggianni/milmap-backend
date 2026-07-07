<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-enrollment: een campagne kan gemarkeerd worden als "onboarding-funnel".
 * Elke nieuwe gebruiker wordt dan bij registratie automatisch in deze campagne
 * geplaatst (dag-0-mail direct, follow-ups via de bestaande engine). Zo hoeft de
 * admin de funnel niet handmatig per gebruiker te versturen.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('mail_campaigns', function (Blueprint $t) {
            $t->boolean('auto_enroll_on_signup')->default(false)->after('status');
            $t->index(['auto_enroll_on_signup']);
        });
    }

    public function down(): void
    {
        Schema::table('mail_campaigns', function (Blueprint $t) {
            $t->dropIndex(['auto_enroll_on_signup']);
            $t->dropColumn('auto_enroll_on_signup');
        });
    }
};
