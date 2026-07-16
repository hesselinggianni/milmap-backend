<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bijlagen (afbeeldingen/bestanden) bij een taak. Worden getoond in het
 * taak-detail én meegegeven aan de prompt wanneer Claude de taak oppakt
 * (de deploy-app downloadt ze lokaal en verwijst ernaar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todo_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('todo_id');
            $table->string('path');            // pad op de 'public' disk
            $table->string('original_name');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
            $table->foreign('todo_id')->references('id')->on('todos')->cascadeOnDelete();
            $table->index('todo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_attachments');
    }
};
