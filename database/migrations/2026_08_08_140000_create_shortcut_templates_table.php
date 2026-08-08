<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kant-en-klare iOS-opdrachten ("Shortcuts") die we in de app aanbieden, bv.
 * "stuur mijn grid naar WhatsApp zodra ik een gemarkeerd gebied binnenkom".
 *
 * Waarom een iCloud-LINK en geen bestand: sinds iOS 15 accepteert de
 * Opdrachten-app alleen shortcuts die via een door Apple ondertekende
 * iCloud-deellink binnenkomen. Een app kan er geen installeren. De werkwijze is
 * dus: de opdracht één keer op een iPhone bouwen, delen, en de link hier
 * invoeren — de app toont 'm dan als "Voeg toe aan Opdrachten".
 *
 * Per-taal labels staan als JSON op de rij (zelfde patroon als bio_links), zodat
 * één opdracht ook één beheer-item blijft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shortcut_templates', function (Blueprint $table) {
            $table->id();
            $table->string('icloud_url');                     // https://www.icloud.com/shortcuts/...
            $table->string('icon', 32)->default('shortcut');  // zie ShortcutTemplate::ICONS
            $table->string('category', 32)->default('overig');
            $table->json('translations')->nullable();         // { nl: {title, sub, steps}, en: {...} }
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shortcut_templates');
    }
};
