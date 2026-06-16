<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gearchiveerde accounts: niet-geverifieerde, onbetaalde accounts worden na 90
 * dagen automatisch gearchiveerd (archived_at gezet). Zo'n account kan niet meer
 * inloggen; bij opnieuw registreren met hetzelfde e-mailadres wordt de slot
 * vrijgegeven (zie RegisterController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('email_verified_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
