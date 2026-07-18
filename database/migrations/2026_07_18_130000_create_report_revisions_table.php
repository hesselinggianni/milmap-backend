<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wijzigingsgeschiedenis van gemarkeerde gebieden (reports). Elke rij is één
 * actie (aangemaakt/bewerkt/verwijderd) met wie het deed, wanneer, en een
 * veld-voor-veld diff ({ veld: { from, to } }). Model naar NineLineRevision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_revisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('report_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 32); // created | updated | deleted | restored
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->foreign('report_id')->references('id')->on('reports')->cascadeOnDelete();
            $table->index(['report_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_revisions');
    }
};
