<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_map_photos', function (Blueprint $table) {
            $table->id();
            $table->uuid('route_map_id');
            $table->string('url');
            $table->string('filename');
            $table->timestamps();

            $table->foreign('route_map_id')->references('id')->on('route_maps')->cascadeOnDelete();
            $table->index(['route_map_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_map_photos');
    }
};
