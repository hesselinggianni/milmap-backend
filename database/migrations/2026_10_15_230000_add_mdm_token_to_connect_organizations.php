<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // API-token waarmee het MDM van de organisatie apparaten in de toegestane lijst
        // zet/haalt. Alleen de sha256-hash wordt bewaard; het token zelf toont Connect één keer.
        Schema::table('connect_organizations', function (Blueprint $t) {
            $t->string('mdm_token_hash', 64)->nullable()->unique();
            $t->string('mdm_token_prefix', 12)->nullable();
            $t->timestamp('mdm_token_created_at')->nullable();
            $t->timestamp('mdm_token_last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('connect_organizations', function (Blueprint $t) {
            $t->dropColumn(['mdm_token_hash', 'mdm_token_prefix', 'mdm_token_created_at', 'mdm_token_last_used_at']);
        });
    }
};
