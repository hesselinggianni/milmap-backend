<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Titel bestond al (activities.title); voegt een vrije omschrijving toe plus
 * een aparte foto's-tabel (1 rij per foto, i.p.v. een JSON-kolom) zodat
 * los uploaden/verwijderen zonder de hele activiteit te herschrijven kan —
 * zelfde patroon als andere per-record media in deze codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('title');
        });

        Schema::create('activity_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('filename');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_photos');
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
