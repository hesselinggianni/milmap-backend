<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales-CRM: deals (kaarten in de pijplijn) + activiteiten (tijdlijn met
 * comments). Eén deal = één relatie/kans met contact- én bedrijfsgegevens,
 * waarde, categorie/tags, fase en laatste/eerstvolgende contactmoment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id();
            $table->string('title', 190);                 // meestal bedrijfs-/dealnaam

            // Contactgegevens
            $table->string('contact_name', 160)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->string('contact_phone', 60)->nullable();
            $table->string('contact_role', 120)->nullable();

            // Bedrijfsgegevens
            $table->string('company_name', 190)->nullable();
            $table->string('company_website', 190)->nullable();
            $table->string('company_address', 255)->nullable();
            $table->string('company_size', 60)->nullable();

            // Waarde + classificatie
            $table->unsignedBigInteger('value')->default(0);   // in centen
            $table->string('currency', 3)->default('EUR');
            $table->string('category', 60)->nullable();         // bv. Software / Samenwerking
            $table->json('tags')->nullable();                   // ["Defensie","Warm"]

            // Pijplijn
            $table->string('stage', 40)->default('lead');       // lead|contacted|demo|negotiation|won|lost
            $table->integer('position')->default(0);            // volgorde binnen de fase
            $table->string('status', 20)->default('open');      // open|won|lost
            $table->string('goal', 190)->nullable();            // doel van de benadering
            $table->text('notes')->nullable();

            // Opvolging / stoplicht
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamp('next_action_at')->nullable();

            // Koppelingen na 1-click-acties
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();     // demo-account
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete(); // omgezet naar partner

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['stage', 'position']);
            $table->index('status');
            $table->index('next_action_at');
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('crm_deals')->cascadeOnDelete();
            $table->string('type', 30)->default('comment');   // comment|call|email|meeting|demo|partner|stage|task|system
            $table->text('body')->nullable();                 // de notitie/comment
            $table->string('goal', 190)->nullable();          // doel van dit contactmoment
            $table->json('meta')->nullable();
            $table->timestamp('happened_at')->nullable();     // wanneer het contact plaatsvond
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['deal_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_deals');
    }
};
