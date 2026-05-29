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
            $table->json('column_settings')->nullable()->after('color')->comment('JSON object with visible column settings');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->dropColumn('column_settings');
        });
    }
};
