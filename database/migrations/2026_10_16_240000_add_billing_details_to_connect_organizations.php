<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
// Factuurgegevens (bedrijfsnaam + adres, versleuteld) op de Connect-organisatie; het factuur-e-mailadres blijft in billing_email.
// stripe_customer_details_hash = hash van wat laatst naar de Stripe-klant is gestuurd, zodat die alleen bij een wijziging wordt bijgewerkt.
return new class extends Migration {
    public function up(): void {
        Schema::table('connect_organizations', function (Blueprint $t) {
            if (! Schema::hasColumn('connect_organizations', 'billing_details')) $t->text('billing_details')->nullable();
            if (! Schema::hasColumn('connect_organizations', 'stripe_customer_details_hash')) $t->string('stripe_customer_details_hash', 64)->nullable();
        });
    }
    public function down(): void {
        $columns = array_values(array_filter(['billing_details', 'stripe_customer_details_hash'], fn ($c) => Schema::hasColumn('connect_organizations', $c)));
        if ($columns) Schema::table('connect_organizations', fn (Blueprint $t) => $t->dropColumn($columns));
    }
};
