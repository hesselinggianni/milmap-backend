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
        Schema::create('generated_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('route_map_id');
            $table->unsignedBigInteger('user_id');
            $table->json('options')->default('{}'); // { terrain: 'footpaths|roads', optimization: 'distance|elevation|time' }
            $table->longText('route_geojson'); // GeoJSON LineString
            $table->float('total_distance_m')->nullable();
            $table->float('total_elevation_m')->nullable();
            $table->float('total_time_minutes')->nullable();
            $table->enum('status', ['generated', 'applied'])->default('generated');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->foreign('route_map_id')->references('id')->on('route_maps')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['route_map_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generated_routes');
    }
};
