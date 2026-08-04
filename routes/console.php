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

// ── De rest van app/Console/Kernel.php::schedule() stond hier NOOIT actief:
// die methode wordt door de Laravel 11-bootstrap niet geladen (geen
// ->withSchedule() in bootstrap/app.php), dus onderstaande commands draaiden
// nooit via cron. Hierheen verplaatst op 2026-08-04.

// emails:fetch (FetchEmails) en check:status (CheckWebsiteStatus) staan hier
// BEWUST NIET in de schedule: ze verwijzen naar App\Http\Controllers\
// EmailController resp. App\Models\Website, die niet (meer) bestaan — dode
// legacy-code, allebei al vervangen door mail:notify-new resp. status:monitor
// hieronder. Nooit gemerkt omdat de hele schedule tot 2026-08-04 stillag; nu
// wél geregistreerd zouden ze elke minuut een exception loggen. Command-
// bestanden laten staan (kunnen weg, maar dat is een aparte opruimactie).

// Status-page-domeinen (milmap.nl/status) monitoren + uptime/incidenten
// bijhouden. withoutOverlapping zodat trage checks niet opstapelen.
Schedule::command('status:monitor')->everyMinute()->withoutOverlapping();

// Nieuwe-mail notificaties voor de admin-app. withoutOverlapping zodat
// een trage IMAP-poll de volgende run niet laat opstapelen.
Schedule::command('mail:notify-new')->everyMinute()->withoutOverlapping();

// Follow-up-campagnemails: scan elke minuut welke regels mogen uitgaan
// (vertraging op dag-niveau, dus per minuut scannen is ruim voldoende).
Schedule::command('mail:process-followups')->everyMinute()->withoutOverlapping();

// Definitief verwijderen wat langer dan 60 dagen in de prullenbak zit.
// Draait 's nachts (lage piek), met overlap-bescherming en logging.
Schedule::command('trash:purge')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/trash-purge.log'));

// Archiveer accounts die na 90 dagen nog niet zijn geverifieerd (en niet
// betalen). Het e-mailadres komt daarmee vrij om opnieuw te registreren.
Schedule::command('users:archive-unverified')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/archive-unverified.log'));

// Genereer elke ochtend automatisch sales-taken uit de actieve signalen
// (aflopende trials, churn-risico, niet-benaderde leads, enz.), zodat de
// takenlijst dagelijks up-to-date staat met concrete verkoop-acties.
Schedule::command('sales:generate-todos')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/sales-todos.log'));

// Dagelijkse SEO-taken uit Google Search Console-signalen (no-op als
// GSC niet is geconfigureerd).
Schedule::command('seo:generate-tasks')
    ->dailyAt('07:15')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/seo-tasks.log'));

// Dagelijkse PageSpeed-meting van de gemonitorde URL's + prestatie-taken
// uit lage scores. Rustig gepland (kan traag zijn: URL's × mobiel/desktop).
Schedule::command('pagespeed:monitor')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/pagespeed.log'));

// Maandelijkse partneruitbetaling: alle pending commissies per partner
// in één Stripe-Connect-transfer (minimum €10, zie PayoutPartners).
Schedule::command('partners:payout')
    ->monthlyOn(1, '08:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/partner-payouts.log'));
