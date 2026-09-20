<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-gebruiker voorkeuren voor een kaart als layer in de workspace op /maps:
 * zichtbaar ja/nee, accentkleur en volgorde. Server-side (niet localStorage)
 * zodat je dezelfde layer-opstelling op telefoon en desktop ziet.
 *
 * Eén rij per (user, map): ook een collaborator heeft eigen voorkeuren op een
 * kaart die hij niet bezit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_layer_prefs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('map_id');
            $table->boolean('visible')->default(true);
            $table->string('color', 20)->default('#2b7fff');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->foreign('map_id')->references('id')->on('maps')->cascadeOnDelete();
            $table->unique(['user_id', 'map_id']);
            $table->index(['user_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_layer_prefs');
    }
};
