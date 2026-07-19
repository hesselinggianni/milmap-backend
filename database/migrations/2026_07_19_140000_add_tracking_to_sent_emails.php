<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Open- en kliktracking op het universele uitgaand-maillog (sent_emails):
 * - token: per verzonden bericht; hangt in de pixel-/klik-URL's in de mail.
 * - status: sent | failed — 'aangekomen' is per definitie een schatting; een
 *   open of klik is het hardste bewijs van aflevering dat SMTP ons geeft.
 * - opened_at/open_count: via de 1×1-pixel (zelfde aanpak als mail_sends).
 * - click_count/last_click_url: aggregaat voor de lijstweergave; de losse
 *   kliks (welke link/knop precies, wanneer) staan in sent_email_clicks.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sent_emails', function (Blueprint $t) {
            $t->string('token', 40)->nullable()->index()->after('lead_id');
            $t->string('status', 20)->default('sent')->after('mailer');
            $t->timestamp('opened_at')->nullable()->after('sent_at');
            $t->unsignedInteger('open_count')->default(0)->after('opened_at');
            $t->unsignedInteger('click_count')->default(0)->after('open_count');
            $t->string('last_click_url', 500)->nullable()->after('click_count');
        });

        Schema::create('sent_email_clicks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sent_email_id')->constrained('sent_emails')->cascadeOnDelete();
            $t->string('url', 500);
            $t->string('label', 120)->nullable();   // linktekst/knoptekst uit de mail
            $t->timestamp('clicked_at');
            $t->timestamps();

            $t->index(['sent_email_id', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_email_clicks');
        Schema::table('sent_emails', function (Blueprint $t) {
            $t->dropColumn(['token', 'status', 'opened_at', 'open_count', 'click_count', 'last_click_url']);
        });
    }
};
