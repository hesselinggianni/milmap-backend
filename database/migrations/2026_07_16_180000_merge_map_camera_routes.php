<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Eenmalige opschoning: oude route-events sloegen het kaartcamera-segment
// (`/map/:id/@lat,lng,zoom`) nog als aparte naam op, waardoor één kaartscherm
// over tientallen rijen versplinterde. De normalisatie laat dat segment nu weg
// (zie AppEventController::normalizeName + services/analytics.js). Hier voegen
// we de al opgeslagen rijen samen naar `/map/:id`. De admin-aggregatie groepeert
// op `name`, dus na deze update tellen ze automatisch bij elkaar op.
return new class extends Migration
{
    public function up(): void
    {
        // Alles vóór het "/@"-segment behouden → `/map/:id/@…` wordt `/map/:id`.
        DB::update(
            "UPDATE app_events SET name = SUBSTRING_INDEX(name, '/@', 1) WHERE type = ? AND name LIKE ?",
            ['route', '%/@%']
        );
    }

    public function down(): void
    {
        // Niet omkeerbaar: de oorspronkelijke camera-posities zijn bewust weg.
    }
};
