<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// First-party site-analytics voor milmap.nl: pageviews + doel-events
// (cta_click, converter-gebruik, minimap-gate enz.). Bewust géén PII:
// visitor_hash = sha256(dag-salt + ip + user-agent) zodat we uniques per dag
// kunnen tellen zonder IP's of cookies op te slaan (Plausible-aanpak).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);                  // pageview | cta_click | mgrs_lookup | ...
            $table->string('path', 300);                  // sitepad, zonder querystring
            $table->string('referrer', 300)->nullable();  // alleen host+pad, extern
            $table->string('locale', 5)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('visitor_hash', 64);           // dag-gebonden, niet herleidbaar
            $table->string('device', 10)->nullable();     // mobile | desktop
            $table->json('meta')->nullable();             // event-specifiek (bv. dest, tab)
            $table->timestamp('occurred_at')->index();

            $table->index(['event', 'occurred_at']);
            $table->index(['path', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_events');
    }
};
