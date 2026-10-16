<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('connect_orders', function (Blueprint $t) {
            $t->unsignedTinyInteger('vat_percent')->default(21);
            $t->unsignedBigInteger('vat_cents')->default(0);
            $t->unsignedBigInteger('total_incl_cents')->default(0);
            $t->string('vat_method', 20)->nullable();
        });
    }
    public function down(): void {
        Schema::table('connect_orders', fn (Blueprint $t) => $t->dropColumn(['vat_percent', 'vat_cents', 'total_incl_cents', 'vat_method']));
    }
};
