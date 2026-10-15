<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // APNs-/FCM-token van de installatie, zodat Connect een apparaat op
        // afstand direct kan laten uitloggen (silent push).
        Schema::table('connect_devices', function (Blueprint $t) {
            $t->text('push_token')->nullable();
            $t->string('push_platform', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('connect_devices', fn (Blueprint $t) => $t->dropColumn(['push_token', 'push_platform']));
    }
};
