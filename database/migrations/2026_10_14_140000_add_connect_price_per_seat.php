<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (! Schema::hasColumn('connect_organizations', 'monthly_price_per_seat_cents')) {
            Schema::table('connect_organizations', fn (Blueprint $t) => $t->unsignedInteger('monthly_price_per_seat_cents')->default(0)->after('monthly_price_cents'));
        }
    }
    public function down(): void {
        if (Schema::hasColumn('connect_organizations', 'monthly_price_per_seat_cents')) Schema::table('connect_organizations', fn (Blueprint $t) => $t->dropColumn('monthly_price_per_seat_cents'));
    }
};
