<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_radio_channels', function (Blueprint $table) {
            $table->id();
            $table->string('mission_id', 36);
            $table->foreign('mission_id')->references('id')->on('missions')->onDelete('cascade');

            $table->string('net_name', 128);            // e.g. "Command Net"
            $table->string('frequency', 32)->nullable(); // e.g. "243.000 MHz"
            $table->string('callsign', 64)->nullable();  // e.g. "ALPHA-1"
            $table->string('encryption', 64)->nullable(); // e.g. "AES-256 / KY-99"
            $table->string('mode', 32)->nullable();      // FM / AM / HF / SATCOM / Digital
            $table->boolean('is_primary')->default(false);
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_radio_channels');
    }
};
