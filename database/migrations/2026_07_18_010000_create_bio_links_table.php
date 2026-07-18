<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link-in-bio links (milmap.nl/link-in-bio) — een platte, geordende lijst met
 * knoppen die vanuit de admin ("Link-in-bio") wordt beheerd en client-side door
 * de Nuxt-pagina wordt uitgelezen. Eén rij per link; de per-taal labels
 * (title/sub) staan als JSON op de rij, zodat een link één beheer-item is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bio_links', function (Blueprint $table) {
            $table->id();
            $table->string('href');                       // doel-URL
            $table->string('icon', 32)->default('link');  // globe|apple|android|partner|link
            $table->string('variant', 16)->default('dark'); // primary|dark|ghost
            $table->json('translations')->nullable();     // { nl: {title, sub}, en: {...}, de, es }
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'sort']);
        });

        // Seed de bestaande 3 links zodat /link-in-bio direct gevuld is.
        $now = now();
        DB::table('bio_links')->insert([
            [
                'href' => 'https://milmap.nl', 'icon' => 'globe', 'variant' => 'ghost',
                'translations' => json_encode([
                    'nl' => ['title' => 'Website milmap.nl', 'sub' => 'Ontdek alle functies'],
                    'en' => ['title' => 'Website milmap.nl', 'sub' => 'Discover every feature'],
                    'de' => ['title' => 'Website milmap.nl', 'sub' => 'Alle Funktionen entdecken'],
                    'es' => ['title' => 'Sitio web milmap.nl', 'sub' => 'Descubre todas las funciones'],
                ]),
                'enabled' => true, 'sort' => 0, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'href' => 'https://apps.apple.com/app/id6788657368', 'icon' => 'apple', 'variant' => 'dark',
                'translations' => json_encode([
                    'nl' => ['title' => 'Download voor iPhone', 'sub' => 'Beschikbaar in de App Store'],
                    'en' => ['title' => 'Download for iPhone', 'sub' => 'Available on the App Store'],
                    'de' => ['title' => 'Für iPhone laden', 'sub' => 'Im App Store verfügbar'],
                    'es' => ['title' => 'Descargar para iPhone', 'sub' => 'Disponible en la App Store'],
                ]),
                'enabled' => true, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'href' => 'https://partners.milmap.nl', 'icon' => 'partner', 'variant' => 'primary',
                'translations' => json_encode([
                    'nl' => ['title' => 'Word partner', 'sub' => 'Aanmelden & commissie verdienen'],
                    'en' => ['title' => 'Become a partner', 'sub' => 'Sign up & earn commission'],
                    'de' => ['title' => 'Partner werden', 'sub' => 'Anmelden & Provision verdienen'],
                    'es' => ['title' => 'Hazte socio', 'sub' => 'Regístrate y gana comisión'],
                ]),
                'enabled' => true, 'sort' => 2, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bio_links');
    }
};
