<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('mission_id', 36);
            $table->foreign('mission_id')->references('id')->on('missions')->onDelete('cascade');

            $table->unsignedBigInteger('user_id')->nullable();
            // action: created, updated, status_changed, approved, locked, unlocked,
            //         collaborator_added, collaborator_removed, briefing_updated
            $table->string('action', 64);
            // JSON diff payload (before/after for relevant fields)
            $table->json('payload')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_audit_log');
    }
};
