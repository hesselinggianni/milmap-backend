<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('map_id');
            $table->unsignedBigInteger('created_by');
            $table->string('token', 32)->unique();
            $table->json('route_map_ids')->nullable();
            $table->json('settings')->nullable();
            $table->string('title')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('map_id')->references('id')->on('maps')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_shares');
    }
};
