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
 *   • status_page_checks     → { domainId: [ {timestamp,status,responseTime,…} ] }, 90d bewaard
 *   • status_page_incidents  → [ Incident ]
 *
 * De data-shape spiegelt exact de Nuxt-interfaces (server/types/status.ts) zodat
 * de status-pagina niets hoeft aan te passen aan de vorm.
 */
class StatusPageMonitor
{
    private const DOMAINS_KEY   = 'status_page_domains';
    private const CHECKS_KEY    = 'status_page_checks';
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

    // ── Checks-opslag ───────────────────────────────────────────────
    private function allChecks(): array
    {
        $m = Setting::get(self::CHECKS_KEY, []);

        return is_array($m) ? $m : [];
    }

    public function checksFor(string $domainId): array
    {
        return $this->allChecks()[$domainId] ?? [];
    }

    // ── Uptime-berekening (poort van statusMonitor.ts) ──────────────
    public function calcUptime(array $checks, int $days = 90): array
    {
        $result = [];
        $now = Carbon::now('UTC');
        for ($i = $days - 1; $i >= 0; $i--) {
            $dateStr   = $now->copy()->subDays($i)->format('Y-m-d');
            $dayChecks = array_values(array_filter($checks, fn ($c) => substr($c['timestamp'], 0, 10) === $dateStr));
            $total     = count($dayChecks);
            if ($total === 0) {
                $result[] = ['date' => $dateStr, 'uptime' => 100, 'status' => 'nodata'];
                continue;
            }
            $up       = count(array_filter($dayChecks, fn ($c) => $c['status'] === 'up'));
            $degraded = count(array_filter($dayChecks, fn ($c) => $c['status'] === 'degraded'));
            $pct      = (int) round((($up + $degraded * 0.5) / $total) * 100);
            $status   = $pct >= 99 ? 'up' : ($pct >= 80 ? 'degraded' : 'down');
            $result[] = ['date' => $dateStr, 'uptime' => $pct, 'status' => $status];
        }

        return $result;
    }

    /** De volledige publieke uptime-rapportage per enabled domein. */
    public function uptimeReport(): array
    {
        $out = [];
        foreach ($this->enabledDomains() as $d) {
            $checks  = $this->checksFor($d['id']);
            $latest  = count($checks) ? $checks[count($checks) - 1] : null;
            $overall = count($checks) === 0
                ? 100
                : (int) round(count(array_filter($checks, fn ($c) => $c['status'] === 'up')) / count($checks) * 100);

            $out[] = [
                'domain'           => $d,
                'currentStatus'    => $latest['status'] ?? 'unknown',
                'lastChecked'      => $latest['timestamp'] ?? null,
                'lastResponseTime' => $latest['responseTime'] ?? null,
                'uptimePct'        => $overall,
                'dailyBars'        => $this->calcUptime($checks, 90),
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
        $checksMap = $this->allChecks();
        $incidents = $this->incidents();
        $cutoff    = Carbon::now('UTC')->subDays(self::RETENTION_DAYS)->toIso8601String();
        $results   = [];

        foreach ($domains as $d) {
            $arr        = $checksMap[$d['id']] ?? [];
            $prevStatus = count($arr) ? $arr[count($arr) - 1]['status'] : null;

            $check = $this->checkDomain($d);
            $arr[] = $check;
            $arr   = array_values(array_filter($arr, fn ($c) => $c['timestamp'] >= $cutoff));
            $checksMap[$d['id']] = $arr;

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
        Setting::put(self::CHECKS_KEY, $checksMap);
        Setting::put(self::INCIDENTS_KEY, array_values($incidents));

        return $results;
    }
}
