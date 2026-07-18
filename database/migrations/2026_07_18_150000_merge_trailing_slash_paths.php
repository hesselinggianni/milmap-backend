<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Eenmalige opschoning: `/en/features/` en `/en/features` zijn dezelfde pagina,
// maar werden als twee aparte namen geteld. De ingest normaliseert trailing
// slashes nu weg (zie AppEventController::normalizeName,
// SiteEventController::store + de clients); hier voegen we de al opgeslagen
// rijen samen. De admin-aggregatie groepeert op naam/pad, dus na deze update
// tellen de varianten automatisch bij elkaar op.
return new class extends Migration
{
    public function up(): void
    {
        // Paden die alléén uit slashes bestaan ('//', '///') eerst naar '/',
        // anders zou TRIM(TRAILING '/') ze tot een lege string strippen.
        DB::update("UPDATE app_events SET name = '/' WHERE type = 'route' AND name REGEXP '^/+$' AND name != '/'");
        DB::update(
            "UPDATE app_events SET name = TRIM(TRAILING '/' FROM name) WHERE type = 'route' AND name LIKE '%/' AND name != '/'"
        );

        DB::update("UPDATE site_events SET path = '/' WHERE path REGEXP '^/+$' AND path != '/'");
        DB::update(
            "UPDATE site_events SET path = TRIM(TRAILING '/' FROM path) WHERE path LIKE '%/' AND path != '/'"
        );
    }

    public function down(): void
    {
        // Niet omkeerbaar: welke rijen ooit een trailing slash hadden is weg —
        // en dat is precies de bedoeling (het waren duplicaten).
    }
};
