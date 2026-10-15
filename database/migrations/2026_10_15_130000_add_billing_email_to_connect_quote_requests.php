<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('connect_quote_requests', function (Blueprint $t) { $t->string('billing_email', 190)->nullable()->after('email'); }); } public function down(): void { Schema::table('connect_quote_requests', fn (Blueprint $t) => $t->dropColumn('billing_email')); } };
