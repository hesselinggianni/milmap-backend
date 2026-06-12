<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads — e-mails van mensen die "hou me op de hoogte" hebben ingevuld op
 * de marketing-landing (milmap.nl/app). Geen account, geen wachtwoord; puur
 * adres + bron-attributie zodat we de release-mail kunnen sturen en zien
 * via welke kanalen leads binnenkomen.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->string('email', 255)->unique();
            // 'source' is een korte label uit de UI ("app-landing", "footer", …);
            // 'utm_source' is de share_uuid van een gebruiker die de link
            // deelde (optioneel — leeg bij organische bezoekers).
            $t->string('source', 64)->nullable();
            $t->string('utm_source', 64)->nullable();
            // We bewaren IP + UA voor anti-spam triage en geen meer dan dat.
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            // notified_at wordt gezet wanneer we de "live"-mail hebben gestuurd
            // zodat het admin-panel zichtbaar maakt wie nog moet horen.
            $t->timestamp('notified_at')->nullable();
            $t->timestamps();

            $t->index(['utm_source']);
            $t->index(['notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
