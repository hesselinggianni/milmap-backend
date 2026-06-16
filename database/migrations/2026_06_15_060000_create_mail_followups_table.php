<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up-regels op een campagne: "stuur template X, N dagen nadat de vorige mail
 * in de keten ontvangen / gelezen / niet-gelezen / niet-ontvangen is". De scheduler
 * (mail:process-followups) maakt hieruit nieuwe mail_sends-rijen (followup_id = deze id).
 *
 * position = volgorde in de keten (1 = direct na de hoofdmail, 2 = na follow-up 1, …).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_followups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained('mail_campaigns')->cascadeOnDelete();
            $t->string('template_key', 64);
            $t->foreignId('category_id')->nullable()->constrained('mail_categories')->nullOnDelete();
            $t->unsignedInteger('delay_days')->default(3);
            // received | read | not_read | not_received  (t.o.v. de vorige mail in de keten)
            $t->string('condition', 20)->default('received');
            $t->unsignedInteger('position')->default(1);
            $t->string('subject_override', 255)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();

            $t->index(['campaign_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_followups');
    }
};
