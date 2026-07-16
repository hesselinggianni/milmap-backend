<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// First-party gebruiksanalyse voor de MilMap-app (niet milmap.nl — dat is
// site_events). Legt vast welke schermen/routes bezocht worden en welke
// knoppen/acties aangeklikt worden, zodat de admin ziet wat het meest wordt
// gebruikt. Bewust géén PII: net als bij site_events alleen een dag-gebonden
// visitor_hash = sha256(dag-salt + ip + user-agent) voor uniques-per-dag.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);                   // route | action
            $table->string('name', 200);                  // routepad (bv. /map/:id) of actie-id
            $table->string('label', 120)->nullable();     // leesbaar label voor acties
            $table->string('platform', 10)->nullable();   // web | ios | android | desktop
            $table->string('device', 10)->nullable();     // mobile | desktop
            $table->string('locale', 5)->nullable();
            $table->string('visitor_hash', 64);           // dag-gebonden, niet herleidbaar
            $table->json('meta')->nullable();             // event-specifiek
            $table->timestamp('occurred_at')->index();

            $table->index(['type', 'occurred_at']);
            $table->index(['type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_events');
    }
};
