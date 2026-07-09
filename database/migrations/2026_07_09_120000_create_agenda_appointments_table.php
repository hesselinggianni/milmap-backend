<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eigen afspraken voor de admin-agenda. De agenda toont daarnaast (read-only)
 * geplande/verzonden campagnes en aangemelde leads — die komen uit hun eigen
 * tabellen en worden niet hier opgeslagen (zie AdminAgendaController::events).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('agenda_appointments', function (Blueprint $t) {
            $t->id();
            $t->string('title', 160);
            $t->text('notes')->nullable();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            $t->boolean('all_day')->default(false);
            $t->string('color', 20)->nullable();     // hex, bv. #6366f1
            $t->string('location', 190)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_appointments');
    }
};
