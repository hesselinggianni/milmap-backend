<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hoogste INBOX-UID die de "nieuwe mail"-poller (mail:notify-new) al heeft
     * gezien voor deze inbox. Berichten met een hogere UID zijn nieuw en leveren
     * een lokale notificatie op in de admin-app. Null = nog nooit gepollt; de
     * eerste run zet alleen de baseline zonder te notificeren (geen spam over
     * bestaande mail).
     */
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('last_seen_uid')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn('last_seen_uid');
        });
    }
};
