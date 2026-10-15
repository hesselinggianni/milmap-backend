<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema, DB};
return new class extends Migration {
    public function up(): void {
        Schema::table('connect_devices', fn (Blueprint $t) => $t->foreignId('organization_id')->nullable()->constrained('connect_organizations')->nullOnDelete());
        DB::table('connect_devices')->orderBy('id')->chunkById(100, function ($devices) {
            foreach ($devices as $device) {
                $org = DB::table('connect_members')->where('user_id', $device->user_id)->where('status', 'active')->orderBy('organization_id')->value('organization_id');
                if ($org) DB::table('connect_devices')->where('id', $device->id)->update(['organization_id' => $org]);
            }
        });
    }
    public function down(): void { Schema::table('connect_devices', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id')); }
};
