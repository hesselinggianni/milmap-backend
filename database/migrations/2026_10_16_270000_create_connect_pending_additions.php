<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    // Gebruikers/apparaten die pas na betaling van extra seats worden toegevoegd.
    public function up(): void {
        Schema::create('connect_pending_additions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('organization_id')->constrained('connect_organizations')->cascadeOnDelete();
            $t->unsignedBigInteger('created_by')->nullable(); $t->string('kind', 16); $t->text('rows')->nullable();
            $t->unsignedInteger('row_count'); $t->unsignedInteger('seats_needed'); $t->uuid('order_id')->nullable()->index();
            $t->string('status', 20)->default('pending'); $t->json('result')->nullable(); $t->timestamp('processed_at')->nullable(); $t->timestamps();
            $t->index(['organization_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('connect_pending_additions'); }
};
