<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (! Schema::hasColumn('connect_organizations', 'monthly_price_cents')) {
            Schema::table('connect_organizations', function (Blueprint $t) {
                $t->unsignedInteger('monthly_price_cents')->default(0)->after('seat_limit');
                $t->unsignedInteger('monthly_price_per_seat_cents')->default(0)->after('monthly_price_cents');
                $t->char('currency', 3)->default('EUR')->after('monthly_price_cents');
            });
        }
    }
    public function down(): void {
        Schema::table('connect_organizations', function (Blueprint $t) {
            $t->dropColumn(['monthly_price_cents', 'monthly_price_per_seat_cents', 'currency']);
        });
    }
};
