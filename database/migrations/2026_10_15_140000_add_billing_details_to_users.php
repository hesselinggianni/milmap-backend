<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->text('billing_details')->nullable();
            $t->timestamp('billing_synced_at')->nullable();
        });
    }
    public function down(): void { Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['billing_details', 'billing_synced_at'])); }
};
