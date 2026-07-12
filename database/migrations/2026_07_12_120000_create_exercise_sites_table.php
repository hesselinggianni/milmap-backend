<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terrein (Site) — vaste locatie-entiteit voor de Oefening-modus.
 *
 * Een terrein omhult een bestaande Map (kaart + lagen) en draagt de huisregels
 * en de vaste rollen (site_members). Aan een terrein koppel je oefeningen
 * (missions.exercise_site_id). Zie docs/oefening-modus.md.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exercise_sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('owner_id');

            // De kaart van dit terrein (OpenLayers-map met lagen/GeoJSON).
            $table->uuid('map_id')->nullable();

            $table->string('name');
            $table->text('description')->nullable();

            // Middelpunt voor initiële kaartweergave.
            $table->decimal('center_lat', 10, 8)->nullable();
            $table->decimal('center_lng', 11, 8)->nullable();

            // Huisregels + versie. Bij elke wijziging bump je rules_version zodat
            // deelnemers opnieuw akkoord moeten geven vóór actieve deelname.
            $table->text('house_rules')->nullable();
            $table->unsignedInteger('rules_version')->default(1);

            $table->string('status')->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('owner_id')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->foreign('map_id')
                ->references('id')->on('maps')
                ->nullOnDelete();

            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_sites');
    }
};
