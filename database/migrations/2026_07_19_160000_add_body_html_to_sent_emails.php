<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bewaar de daadwerkelijk verzonden HTML-body per mail, zodat admin de mail
 * exact kan terugzien zoals de ontvanger hem kreeg (inclusief de op dat moment
 * geldende template/inhoud — templates veranderen, dit log niet).
 * MEDIUMTEXT (16MB) is ruim voldoende voor mail-HTML.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sent_emails', function (Blueprint $t) {
            $t->mediumText('body_html')->nullable()->after('last_click_url');
        });
    }

    public function down(): void
    {
        Schema::table('sent_emails', function (Blueprint $t) {
            $t->dropColumn('body_html');
        });
    }
};
