<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historie van Google PageSpeed Insights (Lighthouse) metingen per URL en
     * strategie (mobile/desktop). Eén rij per meting zodat we trends over tijd
     * kunnen tonen; de admin-koppeling schrijft hier bij elke run naartoe en de
     * takengenerator leest de laatste scores.
     */
    public function up(): void
    {
        Schema::create('pagespeed_reports', function (Blueprint $table) {
            $table->id();

            $table->string('url');
            // 'mobile' of 'desktop'
            $table->string('strategy', 10);

            // Lighthouse-categorie-scores (0–100, null als niet opgevraagd).
            $table->unsignedTinyInteger('performance')->nullable();
            $table->unsignedTinyInteger('accessibility')->nullable();
            $table->unsignedTinyInteger('best_practices')->nullable();
            $table->unsignedTinyInteger('seo')->nullable();

            // Core Web Vitals + kernmetrieken (ruwe waarden).
            $table->float('lcp')->nullable();   // Largest Contentful Paint (s)
            $table->float('fcp')->nullable();   // First Contentful Paint (s)
            $table->float('cls')->nullable();   // Cumulative Layout Shift
            $table->float('tbt')->nullable();   // Total Blocking Time (ms)
            $table->float('si')->nullable();    // Speed Index (s)
            $table->float('ttfb')->nullable();  // Server Response Time (ms)

            // Foutmelding als de meting mislukte (URL onbereikbaar/quota).
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['url', 'strategy', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagespeed_reports');
    }
};
