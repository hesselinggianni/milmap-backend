<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welke meldingsformulieren (Bedreiging / Militair / Omgeving-Weer / eigen) een
 * gebruiker op een kaart gebruikt. De eerste keer dat je een gebied markeert,
 * kiest de gebruiker dit per kaart; server-side zodat de keuze op telefoon en
 * desktop gelijk is.
 *
 * Eén rij per (user, map): ook een collaborator kiest zelf, zonder de keuze van
 * de eigenaar te veranderen. `hidden` = de formulier-sleutels die NIET getoond
 * worden (zelfde vorm als de lokale cache in de app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_report_form_prefs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('map_id');
            $table->json('hidden');
            $table->timestamps();

            $table->foreign('map_id')->references('id')->on('maps')->cascadeOnDelete();
            $table->unique(['user_id', 'map_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_report_form_prefs');
    }
};
