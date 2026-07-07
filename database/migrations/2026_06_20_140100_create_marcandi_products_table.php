<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Producten/regels per shop. `kind` = 'header' (categorie-kop, geen prijs) of
 * 'item' (bestelbaar product). `sort` bewaart de originele volgorde uit de
 * Excel-prijslijst zodat de export (blad 2) er identiek uitziet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marcandi_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('shop_id')->constrained('marcandi_shops')->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('kind', 10)->default('item');   // header | item
            $table->unsignedTinyInteger('level')->default(2);
            $table->string('regel', 20)->nullable();       // origineel regelnummer
            $table->string('code', 30)->nullable();        // artikelcode
            $table->string('name', 255);
            $table->decimal('price', 8, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['shop_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marcandi_products');
    }
};
