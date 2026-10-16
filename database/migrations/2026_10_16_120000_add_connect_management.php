<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema, DB};
return new class extends Migration {
    public function up(): void {
        Schema::table('connect_organizations', function (Blueprint $t) { $t->json('management_policy')->nullable(); });
        Schema::table('connect_members', function (Blueprint $t) { $t->json('permissions')->nullable(); });
        Schema::table('connect_allowed_devices', function (Blueprint $t) { $t->string('assigned_employee', 190)->nullable(); });
        foreach (['maps', 'route_maps', 'missions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('connect_organization_id')->nullable()->constrained('connect_organizations')->restrictOnDelete();
            });
            // Only device accounts are unambiguously organisation-owned. Personal
            // members' historical data must never be silently claimed by an employer.
            DB::table($table)->whereNull('connect_organization_id')->orderBy('id')->chunk(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $org = DB::table('connect_allowed_devices')->where('user_id', $row->owner_id)->value('organization_id');
                    if ($org && DB::table('users')->where('id', $row->owner_id)->where('connect_device_account', true)->exists()) {
                        DB::table($table)->where('id', $row->id)->update(['connect_organization_id' => $org]);
                    }
                }
            });
        }
        Schema::create('connect_audit_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('organization_id')->constrained('connect_organizations')->restrictOnDelete();
            $t->unsignedBigInteger('actor_user_id')->nullable(); $t->unsignedBigInteger('device_id')->nullable();
            $t->string('action', 190); $t->string('resource_type', 100)->nullable(); $t->string('resource_id', 190)->nullable();
            $t->json('details')->nullable(); $t->timestamp('created_at')->useCurrent(); $t->index(['organization_id', 'created_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('connect_audit_events');
        foreach (['maps', 'route_maps', 'missions'] as $table) Schema::table($table, function (Blueprint $t) { $t->dropConstrainedForeignId('connect_organization_id'); });
        Schema::table('connect_allowed_devices', fn (Blueprint $t) => $t->dropColumn('assigned_employee'));
        Schema::table('connect_members', fn (Blueprint $t) => $t->dropColumn('permissions'));
        Schema::table('connect_organizations', fn (Blueprint $t) => $t->dropColumn('management_policy'));
    }
};
