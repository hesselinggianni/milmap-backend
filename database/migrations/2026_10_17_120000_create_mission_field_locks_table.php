<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Veld-locks voor samen bewerken van een missie (waarschuwingsbevel/O-group).
 * Eén rij per (missie, veldpad): wie het veld focust houdt het vast zolang de
 * client heartbeats stuurt (expires_at). Een ander kan overname aanvragen
 * (takeover_*); de houder staat toe/weigert, of na een time-out gaat het over.
 * Zie MissionLiveController.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mission_field_locks', function (Blueprint $t) {
            $t->id();
            $t->uuid('mission_id');
            $t->string('field', 191);
            $t->unsignedBigInteger('user_id');
            $t->timestamp('expires_at');
            $t->unsignedBigInteger('takeover_user_id')->nullable();
            $t->timestamp('takeover_requested_at')->nullable();
            // pending | denied
            $t->string('takeover_status', 10)->nullable();
            $t->unique(['mission_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_field_locks');
    }
};
