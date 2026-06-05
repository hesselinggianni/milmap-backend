<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Marks accounts that were auto-created through an e-mail invitation.
     * Such accounts are "free" and limited to view-only access until they
     * take out a paid subscription (Stripe — wired later). Existing users
     * are unaffected: the column defaults to false so nobody loses access.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('view_only')->default(false)->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('view_only');
        });
    }
};
