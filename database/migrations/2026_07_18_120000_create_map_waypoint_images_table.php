<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto's die bij een waypoint-locatie horen. Opgeslagen op de 'public' disk
 * (zoals checkpoint- en todo-bijlagen) zodat ze direct via een URL te tonen
 * zijn. Één waypoint kan meerdere foto's hebben (max wordt in de controller
 * afgedwongen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_waypoint_images', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('map_waypoint_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->foreign('map_waypoint_id')
                ->references('id')->on('map_waypoints')
                ->cascadeOnDelete();

            $table->index('map_waypoint_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_waypoint_images');
    }
};
