<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GPS track points recorded during a mission.
 * Each row is one position sample from one participant.
 * The frontend batches and POSTs these every ~5 s.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_tracks', function (Blueprint $table) {
            $table->id();
            $table->uuid('mission_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->float('altitude')->nullable();   // metres above sea level
            $table->float('speed')->nullable();       // m/s from GPS
            $table->float('heading')->nullable();     // degrees 0–360
            $table->float('accuracy')->nullable();    // horizontal accuracy in metres

            $table->timestamp('recorded_at');        // device timestamp
            $table->timestamps();

            $table->foreign('mission_id')
                  ->references('id')->on('missions')
                  ->cascadeOnDelete();

            // Fast lookup: latest point per user, full track per user
            $table->index(['mission_id', 'user_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_tracks');
    }
};
