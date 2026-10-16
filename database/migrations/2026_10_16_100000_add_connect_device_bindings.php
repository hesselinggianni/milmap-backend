<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('connect_device_account')->default(false));
        Schema::table('connect_allowed_devices', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->unique()->constrained('users');
            $t->string('binding_hash', 64)->nullable()->unique();
        });
        Schema::table('personal_access_tokens', function (Blueprint $t) {
            // Keep the id after allow-list deletion: missing bindings must fail closed.
            $t->unsignedBigInteger('connect_allowed_device_id')->nullable()->index();
            $t->string('connect_binding_hash', 64)->nullable();
        });
    }
    public function down(): void {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('connect_device_account'));
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->dropColumn(['connect_allowed_device_id', 'connect_binding_hash']));
        Schema::table('connect_allowed_devices', function (Blueprint $t) {
            $t->dropForeign(['user_id']); $t->dropUnique(['user_id']); $t->dropUnique(['binding_hash']);
            $t->dropColumn(['user_id', 'binding_hash']);
        });
    }
};
