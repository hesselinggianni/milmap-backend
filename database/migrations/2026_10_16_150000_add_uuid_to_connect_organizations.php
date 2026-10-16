<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
return new class extends Migration {
    public function up(): void {
        Schema::table('connect_organizations', fn (Blueprint $t) => $t->uuid('uuid')->nullable()->after('id'));
        DB::table('connect_organizations')->whereNull('uuid')->pluck('id')->each(fn ($id) => DB::table('connect_organizations')->where('id', $id)->update(['uuid' => (string) Str::uuid()]));
        Schema::table('connect_organizations', fn (Blueprint $t) => $t->unique('uuid'));
    }
    public function down(): void { Schema::table('connect_organizations', fn (Blueprint $t) => $t->dropColumn('uuid')); }
};
