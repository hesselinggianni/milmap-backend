<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Herkomst-velden zodat een Activity-rij kan tonen dat hij uit Garmin Connect
 * komt (i.p.v. lokaal opgenomen), inclusief het specifieke devicetype.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('source', 16)->default('native')->after('user_id');
            $table->string('garmin_activity_id')->nullable()->unique()->after('source');
            $table->string('garmin_device_name')->nullable()->after('garmin_activity_id');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'garmin_activity_id', 'garmin_device_name']);
        });
    }
};
