<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // APNs-/FCM-token van het toestel waarop deze sessie draait. Een token is
        // een sessie (uitloggen verwijdert het), dus een melding gaat alleen naar
        // toestellen waar het account op dat moment ingelogd is, ook buiten Connect.
        Schema::table('personal_access_tokens', function (Blueprint $t) {
            $t->string('push_token', 512)->nullable();
            $t->string('push_platform', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $t) {
            $t->dropColumn(['push_token', 'push_platform']);
        });
    }
};
