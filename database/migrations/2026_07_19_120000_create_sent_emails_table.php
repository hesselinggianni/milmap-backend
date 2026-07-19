<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Universeel uitgaand-maillog: één rij per daadwerkelijk verzonden e-mail,
 * ongeacht het kanaal (transactioneel: verificatie/wachtwoord/coupon/…, én
 * campagnes). Gevuld door de MessageSent-listener in AppServiceProvider —
 * niets hoeft per mailable geïnstrumenteerd te worden. mail_sends blijft het
 * campagne-log (opens/follow-ups); dit is het antwoord op "welke mails heeft
 * dit adres van ons gekregen".
 *
 * user_id/lead_id zijn snapshots op verzendmoment (nullable, nullOnDelete)
 * zodat het log blijft bestaan als een account/lead verdwijnt.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('sent_emails', function (Blueprint $t) {
            $t->id();
            $t->string('email', 255)->index();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $t->string('subject', 255)->nullable();
            $t->string('mailer', 40)->nullable();      // welke mailer-transport
            // ⚠️ nullable is essentieel: de eerste NOT NULL-timestampkolom krijgt
            // in MySQL/MariaDB anders impliciet ON UPDATE CURRENT_TIMESTAMP,
            // waardoor elke status-update de verzendtijd zou overschrijven.
            $t->timestamp('sent_at')->nullable()->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_emails');
    }
};
