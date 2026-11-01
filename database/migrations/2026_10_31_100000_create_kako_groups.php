<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deelnemersgroepen voor het KAKO-tabblad "Deelnemers". Net als de mappen zijn
 * groepen persoonlijk (per gebruiker) en herbruikbaar over trainingen heen: een
 * klas of ploeg hoef je dan maar één keer in te voeren.
 *
 * De leden staan als JSON in de groep: ze worden altijd als geheel geladen en
 * bewerkt, en er is geen query over groepen heen nodig. Een lid is
 * { id, name, device_id? }; device_id is het id van een Connect-apparaat
 * (connect_allowed_devices) — bewust een kaal getal zonder foreign key, zodat
 * het verwijderen van een apparaat in Connect de groep niet stuk maakt. Wat dat
 * getal betekent (naam, medewerker) wordt alleen getoond aan wie de apparaten
 * in Connect mag zien (zie KakoConnectDevices).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kako_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('members')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kako_groups');
    }
};
