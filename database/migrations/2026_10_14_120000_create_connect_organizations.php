<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('connect_organizations', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('status')->default('active');
            $t->unsignedInteger('seat_limit'); $t->unsignedInteger('monthly_price_cents')->default(0); $t->char('currency', 3)->default('EUR');
            $t->timestamp('starts_at')->nullable(); $t->timestamp('ends_at')->nullable(); $t->timestamps();
        });
        Schema::create('connect_members', function (Blueprint $t) {
            $t->id(); $t->foreignId('organization_id')->constrained('connect_organizations')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('first_name'); $t->string('last_name'); $t->string('email');
            $t->string('role')->default('member'); $t->string('status')->default('pending');
            $t->json('tags')->nullable(); $t->string('token_hash', 64)->nullable()->unique();
            $t->timestamp('expires_at')->nullable(); $t->timestamp('accepted_at')->nullable();
            $t->timestamp('mail_sent_at')->nullable(); $t->timestamps();
            $t->unique(['organization_id', 'email']); $t->index(['user_id', 'status']);
        });
        Schema::create('connect_devices', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('installation_id', 64); $t->string('name'); $t->string('platform', 20);
            $t->timestamp('last_seen_at'); $t->timestamp('revoked_at')->nullable(); $t->timestamps();
            $t->unique(['user_id', 'installation_id']);
        });
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->foreignId('connect_device_id')->nullable()->constrained('connect_devices')->nullOnDelete());
    }
    public function down(): void {
        Schema::table('personal_access_tokens', function (Blueprint $t) { $t->dropConstrainedForeignId('connect_device_id'); });
        Schema::dropIfExists('connect_devices'); Schema::dropIfExists('connect_members'); Schema::dropIfExists('connect_organizations');
    }
};
