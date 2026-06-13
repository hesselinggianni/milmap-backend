<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature requests — ingediend via het publieke formulier op de marketing-site
 * (milmap.nl → "Functionaliteit aanvragen"). Tot nu toe werd zo'n verzoek alleen
 * gemaild; we bewaren het nu ook zodat het admin-panel (/admin/feature-requests)
 * de verzoeken kan tonen, triëren (status/prioriteit) en koppelen aan een
 * bestaande MilMap-gebruiker wanneer het e-mailadres overeenkomt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_requests', function (Blueprint $t) {
            $t->id();
            // Optionele koppeling naar een bestaand account. We zetten 'm bij
            // binnenkomst op basis van e-mailmatch; nullOnDelete zodat het
            // verwijderen van een gebruiker het verzoek niet wegvaagt.
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('user_name', 255);
            $t->string('user_email', 255);
            $t->string('title', 255);
            $t->text('description');
            // low | medium | high (zie FeatureRequestController-validatie)
            $t->string('priority', 16)->default('medium');
            $t->string('category', 100)->nullable();
            // Triage-status: new | planned | in_progress | done | declined
            $t->string('status', 20)->default('new');
            // Vrije notitie voor intern gebruik in het admin-panel.
            $t->text('admin_note')->nullable();
            // Anti-spam triage, net als bij leads.
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamps();

            $t->index('user_email');
            $t->index('status');
            $t->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_requests');
    }
};
