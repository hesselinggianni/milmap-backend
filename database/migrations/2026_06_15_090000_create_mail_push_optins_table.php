<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Push-voorkeur per categorie, per gebruiker. Aanwezigheid van een rij = push AAN voor
 * die categorie (opt-in, default uit). Alleen voor accounts — leads hebben geen app/push.
 * Het e-mailkanaal blijft in mail_opt_outs (e-mail-gekeyd, ook voor leads).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_push_optins', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('category_id')->constrained('mail_categories')->cascadeOnDelete();
            $t->timestamps();

            $t->unique(['user_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_push_optins');
    }
};
