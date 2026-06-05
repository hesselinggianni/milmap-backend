<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('owner_id');

            $table->string('name');
            $table->text('description')->nullable();

            // planning | active | complete
            $table->string('status')->default('planning');

            $table->date('date')->nullable();
            $table->string('time')->nullable();

            // O-group briefing structure (situation, mission, execution, …)
            $table->json('ogroup')->nullable();

            // Linked map descriptor: { id, source: 'server'|'local', title }
            $table->json('map')->nullable();

            $table->timestamps();

            $table->foreign('owner_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missions');
    }
};
