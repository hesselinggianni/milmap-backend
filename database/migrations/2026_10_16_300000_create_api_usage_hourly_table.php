<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-verbruik van derde partijen (kaarttegels, Open-Meteo, geocoding, routing,
 * …) per UTC-uur, per gebruiker en per netwerk — zie App\Services\ApiUsageMeter
 * en admin → Analytics → API-verbruik.
 *
 * user_id en network_hash zijn bewust NOT NULL met een lege default: MySQL ziet
 * NULL's in een unique key als verschillend, waardoor de upsert (INSERT … ON
 * DUPLICATE KEY UPDATE) bij gasten telkens een nieuwe rij zou maken.
 * network_hash is een HMAC van het client-IP — het IP zelf slaan we nooit op.
 * Geen timestamps: `bucket` is de tijd (en zo geen "timestamp NOT NULL ON
 * UPDATE"-valkuil).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_usage_hourly')) {
            return;
        }

        Schema::create('api_usage_hourly', function (Blueprint $table) {
            $table->id();
            $table->dateTime('bucket');                              // begin van het UTC-uur
            $table->string('provider', 32);                          // sleutel uit config/api_usage.php
            $table->string('kind', 40);                              // bv. forecast, tiles, reverse
            $table->enum('source', ['client', 'server']);            // toestel direct of via onze server
            $table->unsignedBigInteger('user_id')->default(0);       // 0 = gast
            $table->char('network_hash', 16)->default('');           // HMAC(ip), '' = onbekend
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedInteger('cache_hits')->default(0);

            $table->unique(['bucket', 'provider', 'kind', 'source', 'user_id', 'network_hash'], 'api_usage_hourly_unique');
            $table->index(['user_id', 'bucket']);
            $table->index(['provider', 'bucket']);
            $table->index(['network_hash', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_hourly');
    }
};
