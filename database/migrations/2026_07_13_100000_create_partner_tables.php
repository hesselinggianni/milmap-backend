<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner-/affiliatesysteem (partners.milmap.nl):
 *
 * - partners            : partneraccount gekoppeld aan een milmap-user, met unieke
 *                         referral-code, commissie- en kortingspercentage en de
 *                         Stripe-Connect-koppeling voor uitbetalingen.
 * - partner_referrals   : welke gebruiker via welke partner is binnengekomen
 *                         (met snapshot van de rates op conversiemoment).
 * - partner_commissions : per betaalde Stripe-factuur van een doorverwezen
 *                         gebruiker de berekende commissie; status pending →
 *                         paid via de maandelijkse payout (partners:payout).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('company_name')->nullable();
            $table->string('referral_code')->unique();
            $table->string('status')->default('pending'); // pending | approved | suspended
            $table->decimal('commission_rate', 5, 2)->default(20.00);
            $table->decimal('discount_rate', 5, 2)->default(10.00);
            $table->string('stripe_account_id')->nullable();
            $table->boolean('stripe_onboarding_complete')->default(false);
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('partner_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->onDelete('cascade');
            $table->foreignId('referred_user_id')->constrained('users')->onDelete('cascade');
            $table->string('referral_code');
            $table->decimal('discount_applied', 5, 2);
            $table->decimal('commission_rate_snapshot', 5, 2);
            $table->timestamp('converted_at');
            $table->timestamps();

            // Eén partner-attributie per gebruiker; de eerste geldige code wint.
            $table->unique('referred_user_id');
        });

        Schema::create('partner_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->onDelete('cascade');
            $table->foreignId('partner_referral_id')->constrained()->onDelete('cascade');
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_invoice_id')->nullable()->unique();
            $table->decimal('gross_amount', 10, 2);
            $table->decimal('commission_amount', 10, 2);
            $table->string('status')->default('pending'); // pending | paid | refunded
            $table->string('stripe_transfer_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_commissions');
        Schema::dropIfExists('partner_referrals');
        Schema::dropIfExists('partners');
    }
};
