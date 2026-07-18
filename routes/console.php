<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Terrain-tile-cache (bergterrein-proxy) binnen de Mapbox-cache-TTL houden.
// Let op: hier plannen, niet in app/Console/Kernel.php — die wordt door de
// Laravel 11-bootstrap (bootstrap/app.php) niet meer geladen.
Schedule::command('tiles:prune-terrain')->dailyAt('04:10');
