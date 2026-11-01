<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * StatusPageMonitor — bewaakt de status-page-domeinen (beheerd in de admin,
 * opgeslagen als setting `status_page_domains`) en berekent de publieke
 * uptime + incidenten die milmap.nl/status toont.
 *
 * WAAROM in de backend: milmap.nl wordt statisch gedeployed (nginx, geen Nitro),
 * dus de status-pagina kan niet op een eigen Node-server leunen. De backend
 * (api.milmap.nl) is de enige altijd-draaiende server → die monitort en levert
 * de data via publieke endpoints, die de statische pagina rechtstreeks ophaalt.
 *
 * Opslag volgt hetzelfde settings-patroon als de domeinen zelf (geen migratie):
 *   • status_page_stats      → { domainId: { days: { 'Y-m-d': {up,degraded,down} }, latest: {…} } },
 *                              90 dagen aan tellers + de laatste check (enkele kB)
 *   • status_page_incidents  → [ Incident ]
 *
 * Eerder stond hier elke losse check (1/min × 90 dagen × domeinen ≈ 36 MB JSON
 * in één settings-rij, elke minuut volledig herschreven; zie LEGACY_CHECKS_KEY).
 * De uptime-balken hebben alleen een percentage per dag nodig, dus bewaren we
 * per dag tellers; `status_page_checks` wordt bij de eerste monitor-run omgezet.
 *
 * De data-shape spiegelt exact de Nuxt-interfaces (server/types/status.ts) zodat
 * de status-pagina niets hoeft aan te passen aan de vorm.
 */
class StatusPageMonitor
{
    private const DOMAINS_KEY   = 'status_page_domains';
    private const STATS_KEY     = 'status_page_stats';
    /** Oude opslag: elke check los. Wordt bij de eerste monitor-run omgezet en gewist. */
    private const LEGACY_CHECKS_KEY = 'status_page_checks';
    private const INCIDENTS_KEY = 'status_page_incidents';
    private const RETENTION_DAYS = 90;
    private const MAX_INCIDENTS  = 200;

    // ── Domeinen ────────────────────────────────────────────────────
    /** @return array<int,array> alle enabled domeinen */
    public function enabledDomains(): array
    {
        $all = Setting::get(self::DOMAINS_KEY, []);
        $all = is_array($all) ? array_values($all) : [];

        return array_values(array_filter($all, fn ($d) => ! empty($d['enabled'])));
    }

    // ── Één domein checken ──────────────────────────────────────────
    public function checkDomain(array $domain): array
    {
        $start = microtime(true);
        $ts    = Carbon::now('UTC')->toIso8601String();
        try {
            $res = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Milmap-StatusMonitor/1.0'])
                ->get($domain['url']);
            $rt = (int) round((microtime(true) - $start) * 1000);

            return [
                'domainId'     => $domain['id'],
                'timestamp'    => $ts,
                'status'       => ! $res->successful() ? 'down' : ($rt > 2000 ? 'degraded' : 'up'),
                'responseTime' => $rt,
                'statusCode'   => $res->status(),
            ];
        } catch (\Throwable $e) {
            return [
                'domainId'     => $domain['id'],
                'timestamp'    => $ts,
                'status'       => 'down',
                'responseTime' => null,
                'error'        => $e->getMessage(),
            ];
        }
    }

    // ── Statistieken-opslag ─────────────────────────────────────────
    /**
     * Per domein: { days: { 'Y-m-d': {up,degraded,down} }, latest: {timestamp,status,responseTime,statusCode?} }.
     *
     * $migrate zet de oude losse checks (status_page_checks, ~36 MB) om. Dat decodeert
     * die hele rij en hoort alleen in de CLI-monitor (geen php-fpm-geheugenlimiet);
     * de publieke endpoints tonen tot de eerste monitor-run gewoon 'unknown'.
     */
    private function loadStats(bool $migrate = false): array
    {
        $stats = Setting::get(self::STATS_KEY);
        if (is_array($stats)) {
            return $stats;
        }

        return $migrate ? $this->statsFromLegacyChecks() : [];
    }

    /** Eenmalige omzetting van de oude opslag; verandert zelf niets in de database. */
    private function statsFromLegacyChecks(): array
    {
        $legacy = Setting::get(self::LEGACY_CHECKS_KEY, []);
        $stats  = [];
        foreach (is_array($legacy) ? $legacy : [] as $domainId => $checks) {
            foreach (is_array($checks) ? $checks : [] as $check) {
                if (isset($check['timestamp'], $check['status'])) {
                    $this->tally($stats[$domainId], $check);
                }
            }
        }

        return $stats;
    }

    /** Verwerk één check in de dagteller van zijn domein en onthoud de laatste. */
    private function tally(?array &$domainStats, array $check): void
    {
        $domainStats ??= ['days' => [], 'latest' => null];

        $day    = substr($check['timestamp'], 0, 10);
        $status = in_array($check['status'], ['up', 'degraded', 'down'], true) ? $check['status'] : 'down';
        $domainStats['days'][$day] ??= ['up' => 0, 'degraded' => 0, 'down' => 0];
        $domainStats['days'][$day][$status]++;

        $latest = $domainStats['latest']['timestamp'] ?? null;
        if ($latest === null || $check['timestamp'] >= $latest) {
            $domainStats['latest'] = array_filter([
                'timestamp'    => $check['timestamp'],
                'status'       => $check['status'],
                'responseTime' => $check['responseTime'] ?? null,
                'statusCode'   => $check['statusCode'] ?? null,
            ], fn ($v) => $v !== null);
        }
    }

    /** Gooi dagen weg die buiten de getoonde RETENTION_DAYS vallen. */
    private function pruneDays(array &$domainStats, Carbon $now): void
    {
        $oldest = $now->copy()->subDays(self::RETENTION_DAYS - 1)->format('Y-m-d');
        foreach (array_keys($domainStats['days'] ?? []) as $day) {
            if ($day < $oldest) {
                unset($domainStats['days'][$day]);
            }
        }
    }

    // ── Uptime-berekening (poort van statusMonitor.ts) ──────────────
    /** @param array<string,array{up?:int,degraded?:int,down?:int}> $dayTallies per datum 'Y-m-d' */
    public function calcUptime(array $dayTallies, int $days = 90): array
    {
        $result = [];
        $now = Carbon::now('UTC');
        for ($i = $days - 1; $i >= 0; $i--) {
            $dateStr  = $now->copy()->subDays($i)->format('Y-m-d');
            $tally    = $dayTallies[$dateStr] ?? [];
            $up       = (int) ($tally['up'] ?? 0);
            $degraded = (int) ($tally['degraded'] ?? 0);
            $total    = $up + $degraded + (int) ($tally['down'] ?? 0);
            if ($total === 0) {
                $result[] = ['date' => $dateStr, 'uptime' => 100, 'status' => 'nodata'];
                continue;
            }
            $pct      = (int) round((($up + $degraded * 0.5) / $total) * 100);
            $status   = $pct >= 99 ? 'up' : ($pct >= 80 ? 'degraded' : 'down');
            $result[] = ['date' => $dateStr, 'uptime' => $pct, 'status' => $status];
        }

        return $result;
    }

    /** De volledige publieke uptime-rapportage per enabled domein. */
    public function uptimeReport(): array
    {
        $stats = $this->loadStats();
        $out   = [];
        foreach ($this->enabledDomains() as $d) {
            $days   = $stats[$d['id']]['days'] ?? [];
            $latest = $stats[$d['id']]['latest'] ?? null;
            $total  = 0;
            $up     = 0;
            foreach ($days as $tally) {
                $up    += (int) ($tally['up'] ?? 0);
                $total += (int) ($tally['up'] ?? 0) + (int) ($tally['degraded'] ?? 0) + (int) ($tally['down'] ?? 0);
            }

            $out[] = [
                'domain'           => $d,
                'currentStatus'    => $latest['status'] ?? 'unknown',
                'lastChecked'      => $latest['timestamp'] ?? null,
                'lastResponseTime' => $latest['responseTime'] ?? null,
                'uptimePct'        => $total === 0 ? 100 : (int) round($up / $total * 100),
                'dailyBars'        => $this->calcUptime($days, self::RETENTION_DAYS),
            ];
        }

        return $out;
    }

    // ── Incidenten ──────────────────────────────────────────────────
    public function incidents(): array
    {
        $i = Setting::get(self::INCIDENTS_KEY, []);

        return is_array($i) ? array_values($i) : [];
    }

    /** Publieke incidentenlijst: nieuwste eerst, max 50. */
    public function publicIncidents(): array
    {
        $incidents = $this->incidents();
        usort($incidents, fn ($a, $b) => strcmp($b['timestamp'], $a['timestamp']));

        return array_slice($incidents, 0, 50);
    }

    // ── Monitor-run: check alle domeinen, log + beheer incidenten ───
    public function monitorAll(): array
    {
        $domains   = $this->enabledDomains();
        $stats     = $this->loadStats(migrate: true);
        $incidents = $this->incidents();
        $now       = Carbon::now('UTC');
        $results   = [];

        foreach ($domains as $d) {
            $prevStatus = $stats[$d['id']]['latest']['status'] ?? null;

            $check = $this->checkDomain($d);
            $this->tally($stats[$d['id']], $check);
            $this->pruneDays($stats[$d['id']], $now);

            // Auto-incident: open bij overgang naar 'down', sluit bij herstel.
            if ($check['status'] === 'down' && $prevStatus !== 'down') {
                $hasOpen = false;
                foreach ($incidents as $inc) {
                    if (($inc['createdBy'] ?? null) === 'monitor'
                        && in_array($d['id'], $inc['domainIds'] ?? [], true)
                        && ($inc['status'] ?? '') !== 'resolved') {
                        $hasOpen = true;
                        break;
                    }
                }
                if (! $hasOpen) {
                    $incidents[] = [
                        'id'        => (string) Str::uuid(),
                        'title'     => ($d['name'] ?? 'Dienst').' is onbereikbaar',
                        'message'   => 'Onze monitor kan '.($d['name'] ?? 'de dienst').' momenteel niet bereiken. We onderzoeken het.',
                        'status'    => 'investigating',
                        'severity'  => 'major',
                        'domainIds' => [$d['id']],
                        'timestamp' => Carbon::now('UTC')->toIso8601String(),
                        'createdBy' => 'monitor',
                    ];
                }
            } elseif ($check['status'] !== 'down' && $prevStatus === 'down') {
                foreach ($incidents as &$inc) {
                    if (($inc['createdBy'] ?? null) === 'monitor'
                        && in_array($d['id'], $inc['domainIds'] ?? [], true)
                        && ($inc['status'] ?? '') !== 'resolved') {
                        $inc['status']     = 'resolved';
                        $inc['resolvedAt'] = Carbon::now('UTC')->toIso8601String();
                    }
                }
                unset($inc);
            }

            $results[] = $check;
        }

        // Bewaar; incidentenlijst gecapt zodat de setting niet ongelimiteerd groeit.
        if (count($incidents) > self::MAX_INCIDENTS) {
            usort($incidents, fn ($a, $b) => strcmp($b['timestamp'], $a['timestamp']));
            $incidents = array_slice($incidents, 0, self::MAX_INCIDENTS);
        }
        // Verwijderde domeinen laten hun historie niet eeuwig achter (uitgeschakelde wel).
        $allDomains = Setting::get(self::DOMAINS_KEY, []);
        $knownIds   = array_column(is_array($allDomains) ? $allDomains : [], 'id');
        $stats      = array_intersect_key($stats, array_flip($knownIds));

        Setting::put(self::STATS_KEY, $stats);
        Setting::put(self::INCIDENTS_KEY, array_values($incidents));
        // Pas wissen nadat de tellers zijn opgeslagen; no-op als de oude rij er niet (meer) is.
        Setting::where('key', self::LEGACY_CHECKS_KEY)->delete();

        return $results;
    }
}
