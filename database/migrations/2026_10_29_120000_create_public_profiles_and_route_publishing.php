<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Openbaar profiel + routes delen tussen gebruikers.
 *
 *  - public_profiles: opt-in profiel (handle, weergavenaam, bio). Staat bewust
 *    in een eigen tabel i.p.v. op users, zodat het nooit per ongeluk mee
 *    serialiseert in een User-JSON.
 *  - activities.is_public / route_maps.is_public: de gebruiker publiceert per
 *    route; zichtbaar is alleen wat zowel gepubliceerd is ÁLS het profiel aan
 *    staat.
 *  - route_maps.cloned_from_*: herkomst wanneer iemand een openbare route
 *    "gebruikt" (kloont), voor de teller en de bronvermelding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('handle', 30)->unique();
            $table->string('display_name', 60)->nullable();
            $table->string('bio', 300)->nullable();
            $table->boolean('enabled')->default(false);
            // Begin en einde (200 m) van openbare tracks wegknippen zodat een
            // thuisadres niet af te lezen is. Standaard aan.
            $table->boolean('hide_endpoints')->default(true);
            $table->timestamps();

            $table->index(['enabled', 'handle']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('map_id');
            $table->timestamp('published_at')->nullable()->after('is_public');
            $table->index(['user_id', 'is_public']);
        });

        Schema::table('route_maps', function (Blueprint $table) {
            $table->boolean('is_public')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->string('cloned_from_type', 16)->nullable();
            $table->string('cloned_from_id', 36)->nullable();
            $table->unsignedBigInteger('cloned_from_user_id')->nullable();
            $table->index(['owner_id', 'is_public']);
            $table->index(['cloned_from_type', 'cloned_from_id']);
        });
    }

    public function down(): void
    {
        Schema::table('route_maps', function (Blueprint $table) {
            $table->dropIndex(['owner_id', 'is_public']);
            $table->dropIndex(['cloned_from_type', 'cloned_from_id']);
            $table->dropColumn(['is_public', 'published_at', 'cloned_from_type', 'cloned_from_id', 'cloned_from_user_id']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_public']);
            $table->dropColumn(['is_public', 'published_at']);
        });

        Schema::dropIfExists('public_profiles');
    }
};
