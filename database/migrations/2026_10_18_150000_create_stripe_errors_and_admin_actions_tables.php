<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Elke fout die Stripe teruggaf (ook de fouten die de flow opving), voor het overzicht in MilMap Admin.
        Schema::create('stripe_errors', function (Blueprint $table) {
            $table->id();
            $table->string('source', 80)->index();
            $table->boolean('handled')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('stripe_code', 80)->nullable()->index();
            $table->string('stripe_type', 60)->nullable();
            $table->string('param', 120)->nullable();
            $table->string('exception', 120);
            $table->text('message');
            $table->string('missing_permission', 80)->nullable()->index();
            $table->string('stripe_request_id', 80)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Wat een beheerder via MilMap Admin in Stripe deed (betaald markeren, crediteren, abonnement wijzigen of stoppen).
        Schema::create('stripe_admin_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('action', 60);
            $table->string('stripe_object', 80)->index();
            $table->json('payload')->nullable();
            $table->boolean('succeeded')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_admin_actions');
        Schema::dropIfExists('stripe_errors');
    }
};
