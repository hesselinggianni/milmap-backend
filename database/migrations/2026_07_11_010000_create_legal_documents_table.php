<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Juridische documenten (legal.milmap.nl) per slug × taal. Beheerd in de admin
 * (Juridische pagina's), publiek uitgelezen door de Nuxt-legal-site.
 *
 * Zelfde platte "één rij per (key, taal)"-vorm als seo_overrides, met een
 * `sections`-JSON voor de gestructureerde, van-koppen-voorziene inhoud die de
 * TOC op de site voedt. Document-brede velden (effective_date/published/sort)
 * herhalen per taalrij — net zoals seo_overrides canonical/robots per rij
 * herhaalt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64);              // bv. 'privacy', 'terms', 'cookies'
            $table->string('locale', 8);             // 'nl' (default) | 'en'
            $table->string('title')->nullable();     // documenttitel in deze taal
            $table->text('intro')->nullable();       // korte inleiding onder de titel
            $table->json('sections')->nullable();    // [{ heading, body }] — voedt de TOC
            $table->date('effective_date')->nullable();
            $table->boolean('published')->default(false);
            $table->integer('sort')->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['slug', 'locale']);
            $table->index('slug');
            $table->index(['published', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
