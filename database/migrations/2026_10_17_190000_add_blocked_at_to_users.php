<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handmatig blokkeren van een gebruiker vanuit admin.milmap.nl (bijv. bij
 * extreem API-verbruik). Een geblokkeerd account kan niet inloggen en krijgt
 * op elke geauthenticeerde route een 403 `account_blocked`
 * (App\Http\Middleware\EnsureNotBlocked). Data blijft staan; deblokkeren
 * zet alles weer open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('archived_at');
            $table->string('blocked_reason', 500)->nullable()->after('blocked_at');
            $table->unsignedBigInteger('blocked_by_id')->nullable()->after('blocked_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['blocked_at', 'blocked_reason', 'blocked_by_id']);
        });
    }
};
