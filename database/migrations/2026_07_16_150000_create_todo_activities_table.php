<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tijdlijn per taak: door mensen geschreven comments én automatische
 * wijzig-logs (status/prioriteit/toegewezene…, ook door Claude). Voedt het
 * "Activiteit"-paneel in het taak-detail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todo_activities', function (Blueprint $table) {
            $table->id();
            $table->string('todo_id');
            $table->string('kind', 20);          // comment | change
            $table->string('author', 80)->nullable();
            $table->text('body')->nullable();    // comment-tekst of wijzig-omschrijving
            $table->json('meta')->nullable();    // bv. { field, from, to }
            $table->timestamp('created_at')->nullable();
            $table->foreign('todo_id')->references('id')->on('todos')->cascadeOnDelete();
            $table->index(['todo_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_activities');
    }
};
