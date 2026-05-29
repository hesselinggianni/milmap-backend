<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_locations', function (Blueprint $table) {
            $table->id();
            $table->uuid('map_id');
            $table->unsignedBigInteger('user_id');

            // Location coordinates with high precision
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);

            // Location metadata
            $table->decimal('accuracy', 5, 2)->nullable(); // meters
            $table->decimal('heading', 6, 2)->nullable(); // degrees 0-360
            $table->decimal('speed', 6, 2)->nullable(); // m/s
            $table->string('device_id')->nullable(); // to identify device/session

            // Tracking
            $table->timestamp('last_updated_at')->useCurrent();
            $table->timestamps();

            // Indexes and constraints
            $table->unique(['map_id', 'user_id']); // One location per user per map
            $table->foreign('map_id')->references('id')->on('maps')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['map_id', 'updated_at']); // For querying recent updates
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_locations');
    }
};
