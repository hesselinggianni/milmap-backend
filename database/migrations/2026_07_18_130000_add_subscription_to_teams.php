<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koppel een team aan het team-abonnement dat het "voedt". Alleen een owner met
 * een actief team-abonnement (team_monthly/team_yearly) heeft een team; de
 * subscription_id legt vast welk abonnement de seats + entitlements levert waar
 * de teamleden op meeliften. Zie config/teams.php voor de inbegrepen seats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_id')->nullable()->after('owner_id');
            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['subscription_id']);
            $table->dropColumn('subscription_id');
        });
    }
};
