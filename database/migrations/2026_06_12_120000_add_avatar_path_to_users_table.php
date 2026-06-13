<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profielfoto: optioneel pad naar de geüploade avatar op de 'public' disk
 * (bv. "avatars/ab12…​.jpg"). De volledige URL wordt server-side afgeleid via
 * de User::getAvatarUrlAttribute()-accessor, zodat de client nooit zelf paden
 * hoeft te plakken. Leeg = val terug op initialen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable()->after('last_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'avatar_path')) {
                $table->dropColumn('avatar_path');
            }
        });
    }
};
