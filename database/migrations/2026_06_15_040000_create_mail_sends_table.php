<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Universeel verzendlog: één rij per individuele mail (campagne én follow-up). Dit is
 * de bron voor "wie heeft de mail ontvangen / geopend". Velden zijn bewust
 * gedenormaliseerd (email/name/language/subject snapshot) zodat het log historisch
 * juist blijft ook als een lead verwijderd wordt of een template verandert.
 *
 * followup_id = 0 betekent de hoofd-(campagne)mail. Een follow-up zet followup_id naar
 * het id van de mail_followups-regel. De sentinel 0 (i.p.v. NULL) maakt
 * unique(campaign_id, followup_id, email) hard betrouwbaar — MySQL ziet NULL namelijk
 * als uniek, waardoor dubbele hoofd-sends anders zouden kunnen ontstaan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_sends', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained('mail_campaigns')->cascadeOnDelete();
            $t->unsignedBigInteger('followup_id')->default(0); // 0 = hoofdmail
            $t->string('email', 255);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $t->string('name', 160)->nullable();
            $t->string('language', 5)->default('nl');
            $t->string('template_key', 64);
            $t->foreignId('category_id')->nullable()->constrained('mail_categories')->nullOnDelete();
            $t->string('subject', 255)->nullable();           // wat er daadwerkelijk verstuurd is
            // pending → queued → sent | failed | skipped_optout | bounced
            $t->string('status', 20)->default('pending');
            $t->string('token', 40)->unique();                // pixel + unsubscribe lookup
            $t->unsignedInteger('position')->default(0);      // volgorde in de follow-up-keten
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->unsignedInteger('open_count')->default(0);
            $t->string('failure_reason', 255)->nullable();
            $t->timestamps();

            $t->unique(['campaign_id', 'followup_id', 'email']);
            $t->index(['campaign_id', 'status']);
            $t->index(['email']);
            $t->index(['campaign_id', 'position', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_sends');
    }
};
