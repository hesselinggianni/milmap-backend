<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trail_routes', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20)->default('osm');
            $table->unsignedBigInteger('source_id');
            $table->string('slug', 120)->unique();
            $table->string('name');
            $table->string('type', 20)->index();
            $table->string('country', 2)->default('NL')->index();
            $table->string('province', 40)->nullable()->index();
            $table->decimal('distance_km', 7, 1);
            $table->boolean('is_loop')->default(false);
            $table->unsignedSmallInteger('segments')->default(1);
            $table->decimal('start_lat', 10, 7);
            $table->decimal('start_lon', 10, 7);
            $table->decimal('min_lat', 10, 7);
            $table->decimal('min_lon', 10, 7);
            $table->decimal('max_lat', 10, 7);
            $table->decimal('max_lon', 10, 7);
            $table->json('geometry');
            $table->string('gpx_path');
            $table->string('network', 20)->nullable();
            $table->string('operator')->nullable();
            $table->string('website')->nullable();
            $table->string('source_url');
            $table->string('attribution');
            $table->boolean('is_published')->default(true)->index();
            $table->timestamps();

            $table->unique(['source', 'source_id']);
            $table->index(['min_lat', 'max_lat', 'min_lon', 'max_lon'], 'trail_routes_bbox_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trail_routes');
    }
};
