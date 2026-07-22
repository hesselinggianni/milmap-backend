<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OTA-bundels voor de Capacitor-app (self-hosted CapGo-achtige update-server).
 * Eén rij per gepubliceerde web-bundel (dist/ als zip) per platform+channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ota_builds', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16)->default('all'); // ios | android | all
            $table->string('channel', 32)->default('production');
            $table->string('version', 32); // marketing-versie, bv. "1.7"
            $table->string('bundle_path');
            $table->string('checksum', 64); // sha256 van de zip
            $table->unsignedBigInteger('size');
            $table->string('min_native_version', 32)->nullable(); // laagste native shell die deze bundle mag draaien
            $table->boolean('mandatory')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['platform', 'channel', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ota_builds');
    }
};
