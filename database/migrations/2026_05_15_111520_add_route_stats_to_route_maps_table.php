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
        Schema::table('route_maps', function (Blueprint $table) {
            $table->bigInteger('pause_time')->default(0);

            $table->bigInteger('total_time')->default(0);
            
            $table->bigInteger('total_distance')->default(0);
            
            $table->bigInteger('total_elevation')->default(0);

            $table->json('meta')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->dropColumn([
                'pause_time',
                'total_time',
                'total_distance',
                'total_elevation',
                'meta',
            ]);
        });
    }
};