<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Moment van de laatste account-reset (AccountReset). De app vergelijkt dit met
// wat ze lokaal kent en wist dan ook haar offline kopie (snapshots, outbox),
// want uitloggen op een Connect-apparaat laat die bewust staan.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'data_reset_at')) return;
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('data_reset_at')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'data_reset_at')) return;
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('data_reset_at');
        });
    }
};
