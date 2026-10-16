<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('connect_allowed_devices', fn (Blueprint $t) => $t->string('platform',16)->default('ios')); }
    public function down(): void { Schema::table('connect_allowed_devices', fn (Blueprint $t) => $t->dropColumn('platform')); }
};
