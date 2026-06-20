<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tijdelijke kledingbestel-lijst (trainingspak / shirts). Bewust losgekoppeld
 * van users — staat op zichzelf en mag er later zo weer uit (drop table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clothing_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 150);
            $table->string('email', 190)->nullable();
            $table->string('note', 500)->nullable();
            // items = [{product, size, qty, price}, ...]
            $table->json('items');
            $table->unsignedInteger('total_qty')->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clothing_orders');
    }
};
