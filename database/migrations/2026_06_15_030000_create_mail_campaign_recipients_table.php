<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staging-doelgroep van een campagne: de beoogde ontvangers die de admin samenstelt
 * (los toevoegen, "alle gebruikers", "alle leads", "iedereen", of handmatige lead)
 * vóór het verzenden. Bij verzenden worden hieruit mail_sends-rijen gemaakt.
 *
 * user_id/lead_id zijn optioneel (een contact kan een gebruiker, een lead, of allebei
 * zijn); 'email' is altijd de canonieke sleutel. unique(campaign_id,email) ontdubbelt.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_campaign_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained('mail_campaigns')->cascadeOnDelete();
            $t->string('email', 255);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $t->string('name', 160)->nullable();
            // hoe deze ontvanger is toegevoegd: manual|all_users|all_leads|everyone|lead
            $t->string('source', 24)->default('manual');
            $t->timestamps();

            $t->unique(['campaign_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_campaign_recipients');
    }
};
