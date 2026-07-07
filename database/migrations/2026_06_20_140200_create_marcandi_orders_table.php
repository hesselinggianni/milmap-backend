<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bestellingen per shop. Losgekoppeld van users. `items` = snapshot van de
 * bestelde regels [{product_id, code, name, price, qty}].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marcandi_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('shop_id')->constrained('marcandi_shops')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('email', 190)->nullable();
            $table->string('edit_token', 64)->nullable()->unique();
            $table->string('note', 500)->nullable();
            $table->json('items');
            $table->unsignedInteger('total_qty')->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
            $table->timestamps();

            $table->index('shop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marcandi_orders');
    }
};
