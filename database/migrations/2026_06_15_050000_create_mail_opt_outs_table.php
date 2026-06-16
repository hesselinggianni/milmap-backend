<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Afmeldingen, e-mail-gekeyd zodat zowel gebruikers (account) als leads (alleen e-mail)
 * met dezelfde tabel werken. category_id NULL = globaal afgemeld (alle marketingmail).
 *
 * Géén unique-index op (email, category_id): MySQL behandelt NULL als uniek, dus dat zou
 * dubbele globale afmeldingen toelaten. Idempotentie wordt in code geregeld
 * (MailOptOutService::optOut gebruikt een whereNull-bewuste upsert).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_opt_outs', function (Blueprint $t) {
            $t->id();
            $t->string('email', 255);
            $t->foreignId('category_id')->nullable()->constrained('mail_categories')->cascadeOnDelete();
            $t->timestamp('unsubscribed_at')->nullable();
            $t->string('source', 24)->default('unsubscribe_link'); // account | unsubscribe_link
            $t->timestamps();

            $t->index(['email', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_opt_outs');
    }
};
