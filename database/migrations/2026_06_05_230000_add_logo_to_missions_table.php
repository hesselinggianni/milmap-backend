<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an optional presentation logo to the missions table.
 *
 * The logo is shown top-center during the mission briefing presentation. It is
 * stored as a (client-side downscaled) base64 data-URL so no separate file
 * storage / upload endpoint is needed and it keeps working offline. LONGTEXT
 * comfortably holds a small PNG data-URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->longText('logo')->nullable()->after('map');
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropColumn('logo');
        });
    }
};
