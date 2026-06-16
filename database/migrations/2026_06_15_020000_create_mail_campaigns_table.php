<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Een campagne = een door de admin verzonnen naam ("Klant worden bij MilMap",
 * "Tips & tricks") + een gekozen backend-mailtemplate (template_key uit de
 * registry config/mail_templates.php) + een categorie. De feitelijke ontvangers
 * staan in mail_campaign_recipients (staging) en de verzonden mails in mail_sends.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('name', 160);
            $t->string('description', 500)->nullable();
            $t->foreignId('category_id')->nullable()->constrained('mail_categories')->nullOnDelete();
            $t->string('template_key', 64);           // verwijst naar de registry
            // draft → sending → sent/partial/failed. (scheduled voor latere planning)
            $t->string('status', 20)->default('draft');
            $t->string('default_locale', 5)->default('nl');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('recipients_built_at')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();

            $t->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_campaigns');
    }
};
