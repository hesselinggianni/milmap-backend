<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('connect_organizations', function (Blueprint $t) { $t->string('contact_name', 190)->nullable(); $t->string('contact_email', 190)->nullable(); $t->string('billing_email', 190)->nullable(); }); }
    public function down(): void { Schema::table('connect_organizations', fn (Blueprint $t) => $t->dropColumn(['contact_name', 'contact_email', 'billing_email'])); }
};
