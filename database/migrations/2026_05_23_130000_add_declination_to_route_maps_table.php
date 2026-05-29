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
            $table->decimal('declination', 6, 2)->nullable()->default(0)->after('color')->comment('Magnetic declination in degrees (+/-)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->dropColumn('declination');
        });
    }
};
