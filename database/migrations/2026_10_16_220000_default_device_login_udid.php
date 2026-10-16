<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
return new class extends Migration {
    public function up(): void {
        Schema::table('connect_organizations', fn (Blueprint $t) => $t->string('device_login_mode', 10)->default('udid')->change());
        DB::table('connect_organizations')->update(['device_login_mode' => 'udid']);
    }
    public function down(): void { Schema::table('connect_organizations', fn (Blueprint $t) => $t->string('device_login_mode', 10)->default('binding')->change()); }
};
