<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wijzigingsgeschiedenis van waypoints. Naar het model van report_revisions:
 * elke rij is één actie (aangemaakt/bewerkt/foto/taak) met wie, wanneer, en een
 * veld-voor-veld diff ({ veld: { from, to } }).
 *
 * De kolom heet bewust `diff` en niet `changes`: een attribuut `changes` botst
 * met Eloquent's interne $changes-property (zie ReportRevision). De API geeft
 * hem wel als `changes` terug, zodat de app één tijdlijn voor beide kan tonen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_waypoint_revisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('map_waypoint_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 32); // created | updated | image_added | image_removed | task_done | task_reopened
            $table->json('diff')->nullable();
            $table->timestamps();

            $table->foreign('map_waypoint_id')->references('id')->on('map_waypoints')->cascadeOnDelete();
            $table->index(['map_waypoint_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_waypoint_revisions');
    }
};
