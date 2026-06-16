<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mail-categorieën = de "soort" mail waar een contact zich los voor kan af-/aanmelden
 * (bv. "Tips & tricks", "Aanbiedingen"). Elke campagne hangt aan één categorie; de
 * opt-out (mail_opt_outs) en de account-instellingen werken per categorie.
 *
 * Transactionele categorieën (is_transactional) worden NOOIT door een opt-out
 * geblokkeerd — een afmelding mag nooit een beveiligings-/wachtwoordmail tegenhouden.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_categories', function (Blueprint $t) {
            $t->id();
            $t->string('key', 64)->unique();          // stabiele sleutel (bv. "tips")
            $t->string('name', 120);                  // weergavenaam in admin + afmeldpagina
            $t->string('description', 255)->nullable();
            $t->boolean('is_transactional')->default(false);
            $t->timestamps();
        });

        // Standaard marketing-categorieën zodat de admin meteen kan beginnen.
        $now = now();
        DB::table('mail_categories')->insert([
            [
                'key' => 'tips', 'name' => 'Tips & tricks',
                'description' => 'Uitleg, tips en nieuwe functies van MilMap.',
                'is_transactional' => false, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'key' => 'offers', 'name' => 'Aanbiedingen & klant worden',
                'description' => 'Acties, kortingen en uitnodigingen om klant te worden.',
                'is_transactional' => false, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_categories');
    }
};
