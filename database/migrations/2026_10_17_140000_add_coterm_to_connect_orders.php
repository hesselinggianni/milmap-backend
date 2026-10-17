<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
// Bijbestelling die meeloopt met de lopende licentie: tot wanneer de eerste (pro rata) periode loopt.
return new class extends Migration {
    public function up(): void { Schema::table('connect_orders', function (Blueprint $t) { if (!Schema::hasColumn('connect_orders','coterm_ends_at')) $t->timestamp('coterm_ends_at')->nullable(); }); }
    public function down(): void { Schema::table('connect_orders', function (Blueprint $t) { if (Schema::hasColumn('connect_orders','coterm_ends_at')) $t->dropColumn('coterm_ends_at'); }); }
};
