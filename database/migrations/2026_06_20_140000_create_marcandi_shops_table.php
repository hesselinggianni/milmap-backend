<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Marcandi bestel-tool (duty-free MARCANDI Buitenland). Losstaand van milmap —
 * leunt alleen op de milmap-admin-auth. Later in z'n geheel te verwijderen.
 * Een "shop" = één bestelronde (bijv. "OC 26-1 Duitsland") met eigen producten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marcandi_shops', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug', 80)->unique();
            $table->string('title', 150);
            $table->string('location', 150)->nullable();
            $table->text('info')->nullable();
            $table->timestamp('closes_at')->nullable();  // sluitingsdatum: daarna dicht
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marcandi_shops');
    }
};
