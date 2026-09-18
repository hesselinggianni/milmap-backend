<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAD-achtige tekenelementen op een plattegrond (muur/kamer/deur/raam/trap/
 * symbool/tekst/vrije lijn). `geometry` bevat de vorm-specifieke punten/afm-
 * etingen, `style` de visuele eigenschappen (kleur, dikte, opacity, …).
 *
 * LET OP: geen kolom `changes` gebruiken — botst met Eloquent's $changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floorplan_elements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('floorplan_id');
            $table->string('type', 20); // wall|room|door|window|stairs|symbol|text|freehand
            $table->json('geometry');
            $table->json('style')->nullable();
            $table->string('label', 200)->nullable();
            $table->integer('z_index')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('floorplan_id')
                ->references('id')->on('waypoint_floorplans')
                ->cascadeOnDelete();

            $table->index(['floorplan_id', 'z_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floorplan_elements');
    }
};
