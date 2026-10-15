<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Beheerder vraagt op afstand een app-update aan (Connect → Apparaten);
        // de app haalt die op bij het eerstvolgende API-verzoek.
        Schema::table('connect_devices', function (Blueprint $t) {
            $t->timestamp('update_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('connect_devices', fn (Blueprint $t) => $t->dropColumn('update_requested_at'));
    }
};
