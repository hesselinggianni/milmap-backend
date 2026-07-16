<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AppSumo lifetime-deal codes.
 *
 * Elke code wordt door AppSumo aan een koper uitgedeeld en kan één keer worden
 * verzilverd via app.milmap.nl/appsumo. Bij verzilvering wordt een MilMap-account
 * aangemaakt en een 100%-korting-abonnement op het AppSumo-product in Stripe gezet.
 * `status` + de unique index op `code` garanderen dat elke code maar één keer telt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appsumo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 200)->unique();
            // available → redeemed. (Ruimte gelaten voor toekomstige 'disabled'.)
            $table->string('status', 20)->default('available')->index();
            $table->foreignId('redeemed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('stripe_subscription_id')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appsumo_codes');
    }
};
