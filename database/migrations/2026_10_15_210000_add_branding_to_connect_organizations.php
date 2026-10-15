<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Huisstijl voor het inlogscherm op zakelijke apparaten (Connect → Huisstijl).
        Schema::table('connect_organizations', function (Blueprint $t) {
            $t->string('logo_path')->nullable();
            $t->string('login_background_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('connect_organizations', function (Blueprint $t) {
            $t->dropColumn(['logo_path', 'login_background_path']);
        });
    }
};
