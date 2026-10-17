<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rate_limit_events', function (Blueprint $table) {
            $table->id();
            // Eén rij per uur × bron × route × gebruiker × IP; hits telt op.
            $table->string('bucket', 40)->unique();
            // internal = onze API gaf 429; external = een derde partij (Open-Meteo, Mapbox, …).
            $table->string('source', 10)->index();
            $table->string('provider', 40)->nullable()->index();
            $table->string('method', 10)->nullable();
            $table->string('route', 255)->nullable();
            $table->string('limiter', 40)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip', 45)->nullable()->index();
            $table->string('installation', 64)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedInteger('max_attempts')->nullable();
            $table->unsignedInteger('retry_after')->nullable();
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_events');
    }
};
