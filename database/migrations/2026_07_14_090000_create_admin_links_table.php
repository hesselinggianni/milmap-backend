<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Snelkoppelingen naar partner-/derdepartijsites in de admin-sidebar,
// optioneel met inloggegevens. Het wachtwoord wordt via de encrypted-cast
// op het model AES-versleuteld opgeslagen (APP_KEY) — zie AdminLink.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_links', function (Blueprint $table) {
            $table->id();
            $table->string('label', 120);
            $table->string('url', 500);
            $table->string('username', 190)->nullable();
            // text: versleutelde payload is fors langer dan het wachtwoord zelf.
            $table->text('password')->nullable();
            $table->string('notes', 500)->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_links');
    }
};
