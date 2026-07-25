<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Van welk platform de lead zich aanmeldde — afgeleid uit de
            // User-Agent bij binnenkomst (zie LeadController::detectPlatform),
            // zodat sales in één oogopslag ziet of dit een iOS-, Android- of
            // desktop/web-bezoeker was (relevant voor app-download-opvolging).
            $table->string('platform', 16)->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
