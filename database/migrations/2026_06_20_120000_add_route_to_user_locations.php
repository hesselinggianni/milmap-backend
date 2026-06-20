<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Welke routekaart een gebruiker op dit moment navigeert (indien bezig).
     * Wordt meegestuurd met de live-locatie zodat de deelnemerskaart kan tonen
     * "navigeert: <route>". Beide nullable: niet iedereen navigeert.
     */
    public function up(): void
    {
        Schema::table('user_locations', function (Blueprint $table) {
            $table->string('route_map_id', 64)->nullable()->after('device_id');
            $table->string('route_map_title')->nullable()->after('route_map_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_locations', function (Blueprint $table) {
            $table->dropColumn(['route_map_id', 'route_map_title']);
        });
    }
};
