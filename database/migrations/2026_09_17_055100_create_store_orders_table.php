<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Eén bestelling kan meerdere kaartontwerpen/producten bevatten (net
            // als ClothingOrder::items) — elk item draagt zijn eigen ontwerp
            // (locatie/tekst/stijl), product, maat en prijs.
            $table->json('items');
            $table->unsignedInteger('total_qty');
            $table->decimal('total_price', 8, 2);

            $table->string('contact_email');
            $table->string('contact_phone');

            // Alleen ingevuld als de bestelling een fysiek product bevat
            // (poster/t-shirt). Bezorging is beperkt tot NL/BE.
            $table->string('recipient_name')->nullable();
            $table->string('address_line')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->nullable();

            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_orders');
    }
};
