<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device-/herkomst-metadata per Sanctum-token, zodat de gebruiker in
 * Account → Beveiliging een login-historie ziet: vanaf welk apparaat / welke
 * client (webapp, iOS, Android, desktop), vanaf welk IP, wanneer ingelogd en
 * laatst actief — en sessies gericht kan intrekken.
 *
 * We hangen dit aan personal_access_tokens omdat een token === een sessie:
 * elke login mint één token, uitloggen verwijdert het token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('abilities');
            $table->text('user_agent')->nullable()->after('ip_address');
            $table->string('platform', 32)->nullable()->after('user_agent');
            $table->string('last_used_ip', 45)->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent', 'platform', 'last_used_ip']);
        });
    }
};
