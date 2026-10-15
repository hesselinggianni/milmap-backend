<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('connect_quote_requests', function(Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $t->string('company_name'); $t->string('contact_name'); $t->string('email'); $t->string('phone')->nullable(); $t->unsignedInteger('seat_count'); $t->string('purpose')->nullable(); $t->text('requirements')->nullable(); $t->string('status')->default('new'); $t->timestamps(); }); } public function down(): void { Schema::dropIfExists('connect_quote_requests'); } };
