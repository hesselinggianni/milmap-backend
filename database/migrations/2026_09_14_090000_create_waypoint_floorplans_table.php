<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plattegronden per waypoint (gebouw). Eén waypoint kan meerdere verdiepingen
 * hebben (floor_index onderscheidt ze); elke verdieping is een los canvas met
 * eigen elementen (zie floorplan_elements).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waypoint_floorplans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('map_waypoint_id');
            $table->string('name', 200)->nullable();
            $table->integer('floor_index')->default(0);
            $table->unsignedInteger('width')->default(1000);
            $table->unsignedInteger('height')->default(700);
            $table->string('background_image_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('map_waypoint_id')
                ->references('id')->on('map_waypoints')
                ->cascadeOnDelete();

            $table->index(['map_waypoint_id', 'floor_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waypoint_floorplans');
    }
};
