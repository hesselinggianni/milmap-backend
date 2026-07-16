<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UTM-herkomst per app-event (utm_source/medium/campaign uit de landings-URL),
 * zodat het analytics-dashboard kan tonen waar bezoekers vandaan komen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_events', function (Blueprint $table) {
            $table->string('utm_source', 120)->nullable()->after('locale');
            $table->string('utm_medium', 120)->nullable()->after('utm_source');
            $table->string('utm_campaign', 120)->nullable()->after('utm_medium');
            $table->index('utm_source');
        });
    }

    public function down(): void
    {
        Schema::table('app_events', function (Blueprint $table) {
            $table->dropIndex(['utm_source']);
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign']);
        });
    }
};
