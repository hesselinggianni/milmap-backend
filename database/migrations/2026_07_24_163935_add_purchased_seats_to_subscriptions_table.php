<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Teamgrootte die de klant bij checkout heeft samengesteld (bv. 25
            // gebruikers), NULL als er geen vooraf gekozen pakket was. Fungeert
            // als ondergrens in Team::seatUsageForOwner() zodat het gekochte
            // pakket niet stilzwijgend krimpt wanneer TeamSeatBillingService
            // op basis van werkelijk gebruik synchroniseert.
            $table->unsignedInteger('purchased_seats')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('purchased_seats');
        });
    }
};
