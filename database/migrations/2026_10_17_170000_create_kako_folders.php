<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mappen in het KAKO-overzicht. Mappen zijn persoonlijk: ook een met jou gedeelde
 * training kun je in je eigen map zetten zonder dat anderen dat zien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kako_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('kako_folder_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('folder_id')->constrained('kako_folders')->cascadeOnDelete();
            $table->uuid('route_assignment_id');
            $table->timestamps();
            $table->foreign('route_assignment_id')->references('id')->on('route_assignments')->cascadeOnDelete();
            // Een training zit per gebruiker in hoogstens één map.
            $table->unique(['user_id', 'route_assignment_id'], 'kako_folder_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kako_folder_items');
        Schema::dropIfExists('kako_folders');
    }
};
