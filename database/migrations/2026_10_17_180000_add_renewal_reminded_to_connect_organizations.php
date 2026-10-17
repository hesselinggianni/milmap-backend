<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('connect_organizations', fn (Blueprint $t) => $t->timestamp('renewal_reminded_for')->nullable());
    }
    public function down(): void
    {
        Schema::table('connect_organizations', fn (Blueprint $t) => $t->dropColumn('renewal_reminded_for'));
    }
};
