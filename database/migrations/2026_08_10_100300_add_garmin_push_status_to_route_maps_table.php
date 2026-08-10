<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status van het pushen van een routekaart naar Garmin als "course" —
 * bijgehouden op route_maps (het topniveau-object waar de pushknop op werkt),
 * niet op generated_routes, die niet altijd bestaat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->string('garmin_course_id')->nullable();
            $table->string('garmin_push_status', 16)->nullable();
            $table->timestamp('garmin_pushed_at')->nullable();
            $table->text('garmin_push_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->dropColumn(['garmin_course_id', 'garmin_push_status', 'garmin_pushed_at', 'garmin_push_error']);
        });
    }
};
