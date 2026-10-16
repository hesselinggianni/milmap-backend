<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('connect_organizations', function (Blueprint $t) { $t->string('stripe_customer_id')->nullable()->unique(); });
        Schema::create('connect_orders', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('organization_id')->constrained('connect_organizations')->restrictOnDelete();
            $t->unsignedBigInteger('ordered_by'); $t->uuid('request_id'); $t->unsignedInteger('seats'); $t->unsignedSmallInteger('term_months');
            $t->string('payment_method', 20); $t->string('status', 30)->default('pending');
            $t->unsignedBigInteger('unit_monthly_cents'); $t->unsignedBigInteger('total_cents'); $t->string('currency', 3);
            $t->text('billing_details'); $t->string('stripe_checkout_id')->nullable()->unique(); $t->string('stripe_subscription_id')->nullable()->unique();
            $t->string('checkout_url', 2048)->nullable(); $t->timestamps(); $t->unique(['organization_id','request_id']);
        });
        Schema::create('connect_order_invoices', function (Blueprint $t) {
            $t->id(); $t->uuid('order_id'); $t->foreign('order_id')->references('id')->on('connect_orders')->restrictOnDelete();
            $t->string('stripe_invoice_id')->unique(); $t->string('number')->nullable(); $t->string('status', 30);
            $t->unsignedBigInteger('total_cents'); $t->string('currency', 3); $t->string('hosted_invoice_url', 2048)->nullable(); $t->string('invoice_pdf', 2048)->nullable();
            $t->timestamp('period_start')->nullable(); $t->timestamp('period_end')->nullable(); $t->timestamp('paid_at')->nullable(); $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('connect_order_invoices'); Schema::dropIfExists('connect_orders');
        Schema::table('connect_organizations', fn (Blueprint $t) => $t->dropColumn('stripe_customer_id'));
    }
};
