<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Elk account krijgt vanaf nu altijd een wachtwoord-hash (een veilige,
     * unieke random string — nooit aan de gebruiker getoond), zodat de
     * `password`-kolom nooit meer NULL hoeft te zijn. `password_set_at` is
     * het echte signaal: NULL betekent "dit is nog de automatisch
     * gegenereerde hash, de gebruiker kent 'm niet" (inloggen kan dan alleen
     * via een e-mailcode of Apple/Google), een timestamp betekent "de
     * gebruiker heeft zelf een wachtwoord ingesteld en kan daarmee inloggen".
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_set_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_set_at');
        });
    }
};
