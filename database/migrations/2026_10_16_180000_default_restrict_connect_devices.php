<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
return new class extends Migration {
    public function up(): void {
        Schema::table('connect_organizations', fn (Blueprint $t) => $t->boolean('restrict_to_allowed_devices')->default(true)->change());
        DB::table('connect_organizations')->update(['restrict_to_allowed_devices' => true]);
    }
    public function down(): void { Schema::table('connect_organizations', fn (Blueprint $t) => $t->boolean('restrict_to_allowed_devices')->default(false)->change()); }
};
