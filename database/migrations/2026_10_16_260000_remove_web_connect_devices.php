<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    // Browserlogins zijn geen Connect-apparaat meer: bestaande registraties opruimen,
    // zodat ze geen apparaatlicentie meer bezetten.
    public function up(): void {
        $ids = DB::table('connect_devices')->where('platform', 'web')->pluck('id');
        if ($ids->isEmpty()) return;
        DB::table('personal_access_tokens')->whereIn('connect_device_id', $ids)->update(['connect_device_id' => null]);
        DB::table('connect_devices')->whereIn('id', $ids)->delete();
    }
    public function down(): void {}
};
