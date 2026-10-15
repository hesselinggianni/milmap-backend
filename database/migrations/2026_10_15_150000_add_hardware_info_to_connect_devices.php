<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('connect_devices', function (Blueprint $t) {
            $t->string('vendor_id', 64)->nullable()->after('installation_id'); // identifierForVendor / Android ID
            $t->string('model', 80)->nullable()->after('name');                // bijv. iPad13,8
            $t->string('os_version', 40)->nullable()->after('platform');
            $t->string('app_version', 40)->nullable()->after('os_version');
        });
    }

    public function down(): void
    {
        Schema::table('connect_devices', function (Blueprint $t) {
            $t->dropColumn(['vendor_id', 'model', 'os_version', 'app_version']);
        });
    }
};
