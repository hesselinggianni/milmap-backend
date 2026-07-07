<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Door de admin zelf opgemaakte mailtemplates (blok-gebaseerd: eyebrow, titel,
 * tekst, afbeelding, knop, lijst, divider). Ze lopen door DEZELFDE fundering als
 * de code-templates: MailTemplateRegistry mergt ze in, ze renderen via de
 * gedeelde `emails.custom`-view (die `emails.layout` gebruikt), en ze werken in
 * campagnes, follow-ups, preview en verzenden zonder aparte code.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_custom_templates', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();          // registry-sleutel (slug)
            $t->string('label', 160);
            $t->string('description', 500)->nullable();
            $t->json('locales');                       // ["nl"] of ["nl","en"]
            $t->json('subjects');                      // {"nl":"...","en":"..."}
            $t->json('blocks');                        // {"nl":[{type,...}], ...}
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_custom_templates');
    }
};
