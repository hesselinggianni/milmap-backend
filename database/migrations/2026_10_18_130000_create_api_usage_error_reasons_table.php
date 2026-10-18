<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waarom een verzoek naar een derde partij mislukte — naast de teller `errors`
 * in api_usage_hourly. Zelfde sleutel (uur, provider, kind, bron, gebruiker,
 * netwerk) plus `reason`, met per rij hoeveel fouten daarvoor waren:
 *
 *   http_<status>  de dienst antwoordde met een niet-2xx status (http_429, http_404, …)
 *   offline        het toestel had geen verbinding
 *   network        geen antwoord (DNS, TLS, verbroken verbinding, CORS)
 *   timeout        server-side time-out
 *   tile_error     tegel kon niet laden (de status is in OpenLayers niet te zien)
 *   image_error    <img> kon niet laden
 *   exception      onverwachte fout in onze eigen aanroep
 *   unknown        geen reden bekend
 *
 * Bewust alleen deze korte codes: geen URL's of foutteksten (tokens, coördinaten).
 * user_id en network_hash zijn NOT NULL met een lege default om dezelfde reden
 * als in api_usage_hourly (NULL's in een unique key zijn "verschillend").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_usage_error_reasons')) {
            return;
        }

        Schema::create('api_usage_error_reasons', function (Blueprint $table) {
            $table->id();
            $table->dateTime('bucket');                              // begin van het UTC-uur
            $table->string('provider', 32);
            $table->string('kind', 40);
            $table->enum('source', ['client', 'server']);
            $table->unsignedBigInteger('user_id')->default(0);       // 0 = gast
            $table->char('network_hash', 16)->default('');
            $table->string('reason', 24);
            $table->unsignedInteger('count')->default(0);

            $table->unique(['bucket', 'provider', 'kind', 'source', 'user_id', 'network_hash', 'reason'], 'api_usage_error_reasons_unique');
            $table->index(['user_id', 'bucket']);
            $table->index(['provider', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_error_reasons');
    }
};
