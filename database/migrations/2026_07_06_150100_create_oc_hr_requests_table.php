<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bijzonderheden (HR) — verzoeken met één of meer van/tot-periodes + opmerking.
 * Onderdeel van de OC-tool (oc.milmap.nl), losstaand van milmap-core.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oc_hr_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 150);
            $table->string('email', 190);
            // items = [{from, to, days, note}]
            $table->json('items');
            $table->unsignedInteger('total_days')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oc_hr_requests');
    }
};
