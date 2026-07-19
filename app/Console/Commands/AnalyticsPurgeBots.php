<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verwijder historisch botverkeer uit site_events dat door de UA-filter
 * (BotDetector) heen glipte: crawlers met een gespoofte browser-UA die de
 * programmatic-SEO-pagina's (coordinaten/steden, mgrs-kaart, opleidingen, …)
 * afstruinen. Vooruit lost de client-side interactie-gate dit op (events
 * worden pas verstuurd na een echt menselijk signaal); dit command maakt de
 * al opgeslagen data met terugwerkende kracht schoon.
 *
 * Heuristiek — een (dag-gebonden) visitor_hash telt als bot wanneer hij:
 *   - géén enkel doel-event heeft (cta_click, mgrs_convert, lead_submit, …);
 *   - uitsluitend programmatic-SEO-pagina's bekeek (niets anders); en
 *   - minstens --min verschillende van die pagina's aandeed.
 * Echte bezoekers landen vanuit Google op één (soms twee) stadspagina's of
 * bezoeken daarnaast ook home/features/pricing — die blijven dus staan.
 *
 * Standaard dry-run: toont wat er weg zou gaan. Pas --force verwijdert echt.
 */
class AnalyticsPurgeBots extends Command
{
    protected $signature = 'analytics:purge-bots
        {--days= : Alleen events uit de laatste N dagen bekijken (default: alles)}
        {--min=4 : Minimum aantal verschillende SEO-pagina\'s per bezoeker om als bot te tellen}
        {--force : Echt verwijderen (zonder deze vlag alleen tonen)}';

    protected $description = 'Verwijder bot-verkeer (gespoofte UA, alleen SEO-pagina-hops) uit site_events.';

    /** Padfragmenten van de programmatic-SEO-clusters op milmap.nl. */
    private const SEO_FRAGMENTS = [
        'coordinaten', 'mgrs-kaart', 'mgrs', 'oefenterreinen', 'flyover',
        'opleidingen', 'kaartlezen', 'routeplanning', 'missievoorbereiding',
    ];

    public function handle(): int
    {
        $min   = max(2, (int) $this->option('min'));
        $force = (bool) $this->option('force');
        $days  = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;

        // SQL-conditie: pad zit in één van de SEO-clusters. Locale-prefix
        // (/en, /de, /es) vangt de wildcard vóór het fragment af.
        $seoCond = '(' . implode(' OR ', array_map(
            fn ($f) => "path LIKE '%/{$f}/%' OR path LIKE '%/{$f}'",
            self::SEO_FRAGMENTS
        )) . ')';

        $window = $days ? 'AND occurred_at >= ?' : '';
        $bind   = $days ? [now()->subDays($days)] : [];

        // Per bezoeker-hash: totaal, doel-events en SEO-pageviews. Bot =
        // alles-SEO + nul acties + genoeg verschillende pagina's.
        $suspects = DB::select("
            SELECT visitor_hash,
                   COUNT(*)                                                        AS total,
                   SUM(CASE WHEN event != 'pageview' THEN 1 ELSE 0 END)            AS actions,
                   SUM(CASE WHEN event = 'pageview' AND {$seoCond} THEN 1 ELSE 0 END) AS seo_views,
                   COUNT(DISTINCT CASE WHEN event = 'pageview' AND {$seoCond} THEN path END) AS seo_paths
            FROM site_events
            WHERE 1=1 {$window}
            GROUP BY visitor_hash
            HAVING actions = 0 AND seo_views = total AND seo_paths >= ?
        ", [...$bind, $min]);

        if (! $suspects) {
            $this->info('Geen bot-verdachte bezoekers gevonden.');
            return self::SUCCESS;
        }

        $hashes      = array_column($suspects, 'visitor_hash');
        $totalEvents = array_sum(array_column($suspects, 'total'));

        $this->line(sprintf(
            '%s bot-verdachte bezoekers, samen %s events (>= %d verschillende SEO-pagina\'s, 0 doel-events, niets anders bekeken).',
            number_format(count($hashes)), number_format($totalEvents), $min
        ));

        // Steekproef zodat je in de dry-run kunt beoordelen of het klopt.
        foreach (array_slice($suspects, 0, 10) as $s) {
            $paths = DB::table('site_events')->where('visitor_hash', $s->visitor_hash)
                ->orderBy('occurred_at')->limit(6)->pluck('path')->implode(' → ');
            $this->line(sprintf('  %s… %d views: %s', substr($s->visitor_hash, 0, 10), $s->total, $paths));
        }

        if (! $force) {
            $this->warn('Dry-run — er is niets verwijderd. Draai met --force om echt op te schonen.');
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach (array_chunk($hashes, 500) as $chunk) {
            $deleted += DB::table('site_events')->whereIn('visitor_hash', $chunk)->delete();
        }
        $this->info(number_format($deleted) . ' events verwijderd.');

        return self::SUCCESS;
    }
}
