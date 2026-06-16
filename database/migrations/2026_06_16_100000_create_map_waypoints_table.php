<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_waypoints', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('map_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // local_id = Date.now() timestamp from the client (used for dedup + matching)
            $table->unsignedBigInteger('local_id');
            $table->decimal('lon', 11, 7);
            $table->decimal('lat', 10, 7);
            $table->string('mgrs', 20)->nullable();
            $table->string('label', 200)->nullable();
            $table->string('color', 20)->default('#2b7fff');
            $table->string('icon', 30)->default('pin');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('map_id')->references('id')->on('maps')->cascadeOnDelete();
            $table->index(['map_id', 'deleted_at']);
            $table->unique(['map_id', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_waypoints');
    }
};
