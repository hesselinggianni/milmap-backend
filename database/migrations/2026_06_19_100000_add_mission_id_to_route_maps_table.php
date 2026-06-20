<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->uuid('mission_id')->nullable()->after('map_id');
            $table->foreign('mission_id')
                ->references('id')
                ->on('missions')
                ->nullOnDelete();
            $table->index('mission_id');
        });
    }

    public function down(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->dropForeign(['mission_id']);
            $table->dropIndex(['mission_id']);
            $table->dropColumn('mission_id');
        });
    }
};
