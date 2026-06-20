<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_risks', function (Blueprint $table) {
            $table->id();
            $table->string('mission_id', 36);
            $table->foreign('mission_id')->references('id')->on('missions')->onDelete('cascade');

            $table->string('description', 512);
            // 1 (low) – 5 (critical)
            $table->unsignedTinyInteger('likelihood')->default(2);
            $table->unsignedTinyInteger('impact')->default(2);
            $table->text('mitigation')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_risks');
    }
};
