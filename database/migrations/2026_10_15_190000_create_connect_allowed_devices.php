<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Vooraf toegestane apparaten (CSV-upload op de Connect-apparatenpagina).
        // Zo'n apparaat heeft zijn licentieplaats al, ook vóór de eerste login.
        Schema::create('connect_allowed_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained('connect_organizations')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('udid', 64);
            $t->timestamps();
            $t->unique(['organization_id', 'udid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_allowed_devices');
    }
};
