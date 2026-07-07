<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO-overrides per milmap.nl-pagina × taal. Beheerd in de admin (SEO-CMS),
 * publiek uitgelezen door de Nuxt-site om de page-meta te overschrijven.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('page_key', 64);          // bv. 'home', 'pricing' (uit config/seo_pages)
            $table->string('locale', 8);             // 'nl' | 'en' | 'de' | 'es'
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image')->nullable();
            $table->string('canonical')->nullable();
            $table->string('robots', 64)->nullable(); // bv. 'index,follow' of 'noindex,nofollow'
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['page_key', 'locale']);
            $table->index('page_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_overrides');
    }
};
