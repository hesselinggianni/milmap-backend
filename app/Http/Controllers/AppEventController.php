<?php

namespace App\Http\Controllers;

use App\Models\AppEvent;
use App\Models\SiteEvent;
use App\Support\GeoIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * First-party gebruiksanalyse-endpoint voor de MilMap-app.
 *
 * Privacy-uitgangspunten (identiek aan SiteEventController, defensie-doelgroep):
 * - geen cookies, geen fingerprinting;
 * - IP wordt NIET opgeslagen — alleen sha256(dag-salt + ip + user-agent)
 *   zodat unieke gebruikers per dag telbaar zijn zonder herleidbaarheid;
 * - alleen aggregatie; individuele events zijn niet aan een account gekoppeld.
 *
 * De app stuurt events gebufferd in batches (zie services/analytics.js), dus
 * store() accepteert een array van maximaal 50 events per verzoek.
 */
class AppEventController extends Controller
{
    /** Maximaal aantal events per batch-verzoek. */
    private const MAX_BATCH = 50;

    public function store(Request $request): \Illuminate\Http\Response
    {
        // Bot-/crawler-verkeer (Googlebot, Lighthouse/PageSpeed, linkpreviews,
        // monitoring) telt niet mee: stil accepteren zonder iets op te slaan,
        // zodat bots geen oracle hebben om de filtering te omzeilen.
        if (\App\Support\BotDetector::isBot($request->userAgent())) {
            return response()->noContent();
        }

        // Lokale ontwikkeling (backend + app op dezelfde machine) telt nooit
        // mee — vangnet naast de client-side uitsluiting van dev/localhost in
        // services/analytics.js (trackingEnabled). Op productie zit nginx
        // ervoor en is het request-IP nooit loopback.
        if (in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            return response()->noContent();
        }

        $data = $request->validate([
            'events'              => 'required|array|max:' . self::MAX_BATCH,
            'events.*.type'       => 'required|string|in:route,action',
            'events.*.name'       => 'required|string|max:200',
            'events.*.label'      => 'nullable|string|max:120',
            'events.*.platform'   => 'nullable|string|in:web,ios,android,desktop',
            'events.*.authed'     => 'nullable|boolean',
            'events.*.locale'     => 'nullable|string|max:5',
            'events.*.meta'       => 'nullable|array',
            'events.*.utm_source'   => 'nullable|string|max:120',
            'events.*.utm_medium'   => 'nullable|string|max:120',
            'events.*.utm_campaign' => 'nullable|string|max:120',
        ]);

        $ua     = (string) $request->userAgent();
        $device = preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : 'desktop';
        $hash   = hash('sha256', now()->toDateString() . config('app.key') . $request->ip() . $ua);
        // Land één keer per verzoek bepalen (ISO-2, zonder het IP op te slaan).
        $country = GeoIp::country($request);
        $now    = now();

        $rows = [];
        foreach ($data['events'] as $e) {
            $rows[] = [
                'type'         => $e['type'],
                'name'         => $this->normalizeName($e['type'], $e['name']),
                'label'        => isset($e['label']) ? mb_substr($e['label'], 0, 120) : null,
                'platform'     => $e['platform'] ?? 'web',
                'device'       => $device,
                'country'      => $country,
                'is_authed'    => array_key_exists('authed', $e) ? (bool) $e['authed'] : null,
                'locale'       => $e['locale'] ?? null,
                'utm_source'   => isset($e['utm_source']) ? mb_substr($e['utm_source'], 0, 120) : null,
                'utm_medium'   => isset($e['utm_medium']) ? mb_substr($e['utm_medium'], 0, 120) : null,
                'utm_campaign' => isset($e['utm_campaign']) ? mb_substr($e['utm_campaign'], 0, 120) : null,
                'visitor_hash' => $hash,
                'meta'         => isset($e['meta']) ? json_encode(array_slice($e['meta'], 0, 10)) : null,
                'occurred_at'  => $now,
            ];
        }

        if ($rows) {
            AppEvent::insert($rows);
        }

        return response()->noContent();
    }

    /**
     * Collabeer variabele segmenten (ids, uuids) in routepaden zodat
     * `/map/123` en `/map/abc-uuid` samen op `/map/:id` uitkomen — anders
     * ontploft de cardinaliteit en is de top-lijst onbruikbaar. De client
     * doet dit al, dit is de server-side vangnet.
     */
    private function normalizeName(string $type, string $name): string
    {
        $name = strtok($name, '?');                       // drop querystring
        if ($type !== 'route') {
            return mb_substr($name, 0, 200);
        }
        $segments = array_values(array_filter(
            explode('/', $name),
            // Kaartcamera-segment (`@lat,lng,zoom`) is geen apart scherm → weg,
            // zodat alle `/map/:id/@…` samen op `/map/:id` uitkomen.
            fn ($seg) => ! str_starts_with($seg, '@')
        ));
        $segments = array_map(function ($seg) {
            if ($seg === '') return $seg;
            // puur numeriek, uuid, of lange hex/token → :id
            if (preg_match('/^\d+$/', $seg)) return ':id';
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $seg)) return ':id';
            if (preg_match('/^[0-9a-f]{16,}$/i', $seg)) return ':id';
            return $seg;
        }, $segments);

        // Trailing slash weg (behalve op de root): `/hub/` en `/hub` zijn
        // hetzelfde scherm en moeten samen tellen.
        return mb_substr(rtrim(implode('/', $segments), '/'), 0, 200) ?: '/';
    }

    // ── Admin: aggregaties voor het analytics-dashboard ──────────────────

    /** Platform-sleutels van de MilMap-app (naast 'site' voor milmap.nl). */
    private const APP_PLATFORMS = ['web', 'ios', 'android', 'desktop'];

    public function adminSummary(Request $request): \Illuminate\Http\JsonResponse
    {
        // Periode: relatief via ?days, óf een exact venster via ?from&?to
        // (ISO-datums, bv. voor "vandaag"/"gisteren"). $until blijft null bij
        // een relatieve periode (loopt dan door tot nu).
        $days  = max(1, min(365, (int) $request->query('days', 30)));
        $since = now()->subDays($days)->startOfDay();
        $until = null;

        $fromParam = $request->query('from');
        $toParam   = $request->query('to');
        if ($fromParam) {
            try { $since = \Illuminate\Support\Carbon::parse($fromParam)->startOfDay(); }
            catch (\Throwable $e) { /* val terug op days-venster */ }
        }
        if ($toParam) {
            try { $until = \Illuminate\Support\Carbon::parse($toParam)->endOfDay(); }
            catch (\Throwable $e) { $until = null; }
        }

        // Platform-filter: all | site (milmap.nl) | web (app.milmap.nl) | ios | android | desktop
        $platform = (string) $request->query('platform', 'all');
        if ($platform !== 'all' && $platform !== 'site' && ! in_array($platform, self::APP_PLATFORMS, true)) {
            $platform = 'all';
        }
        $appPlatformFilter = in_array($platform, self::APP_PLATFORMS, true) ? $platform : null;
        $includeApp  = $platform === 'all' || $appPlatformFilter !== null;
        $includeSite = $platform === 'all' || $platform === 'site';

        // Verse query-builders (elke aanroep = nieuwe builder).
        $appBase = function () use ($since, $until, $appPlatformFilter) {
            $q = AppEvent::where('occurred_at', '>=', $since);
            if ($until) $q->where('occurred_at', '<=', $until);
            if ($appPlatformFilter) $q->where('platform', $appPlatformFilter);
            return $q;
        };
        $siteBase = function () use ($since, $until) {
            $q = SiteEvent::where('occurred_at', '>=', $since);
            if ($until) $q->where('occurred_at', '<=', $until);
            return $q;
        };

        // ── Top-routes (schermen) — app-routes + milmap.nl-pageviews ──
        $routeLists = [];
        if ($includeApp) {
            $routeLists[] = $appBase()->where('type', 'route')
                ->select('name', DB::raw('COUNT(*) as total'), DB::raw('COUNT(DISTINCT visitor_hash) as uniques'))
                ->groupBy('name')->orderByDesc('total')->limit(80)->get();
        }
        if ($includeSite) {
            $routeLists[] = $siteBase()->where('event', 'pageview')
                ->select('path as name', DB::raw('COUNT(*) as total'), DB::raw('COUNT(DISTINCT visitor_hash) as uniques'))
                ->groupBy('path')->orderByDesc('total')->limit(80)->get();
        }
        $topRoutes = $this->mergeCounts($routeLists, 50);

        // ── Top-acties (knoppen / site-doelen) ──
        $actionLists = [];
        if ($includeApp) {
            $actionLists[] = $appBase()->where('type', 'action')
                ->select('name', DB::raw('MAX(label) as label'), DB::raw('COUNT(*) as total'), DB::raw('COUNT(DISTINCT visitor_hash) as uniques'))
                ->groupBy('name')->orderByDesc('total')->limit(80)->get();
        }
        if ($includeSite) {
            $actionLists[] = $siteBase()->where('event', '!=', 'pageview')
                ->select('event as name', DB::raw('COUNT(*) as total'), DB::raw('COUNT(DISTINCT visitor_hash) as uniques'))
                ->groupBy('event')->orderByDesc('total')->limit(80)->get();
        }
        $topActions = $this->mergeCounts($actionLists, 50);

        // ── Totalen ──
        $routeViews = 0; $actionClicks = 0; $activeUsers = 0;
        if ($includeApp) {
            $routeViews   += $appBase()->where('type', 'route')->count();
            $actionClicks += $appBase()->where('type', 'action')->count();
            $activeUsers  += $appBase()->distinct('visitor_hash')->count('visitor_hash');
        }
        if ($includeSite) {
            $routeViews   += $siteBase()->where('event', 'pageview')->count();
            $actionClicks += $siteBase()->where('event', '!=', 'pageview')->count();
            $activeUsers  += $siteBase()->distinct('visitor_hash')->count('visitor_hash');
        }

        // ── Per platform (site + app-platforms) ──
        $byPlatform = [];
        if ($includeApp) {
            foreach ($appBase()->select('platform', DB::raw('COUNT(*) as total'))->groupBy('platform')->get() as $r) {
                $byPlatform[] = ['platform' => $r->platform ?: 'onbekend', 'total' => (int) $r->total];
            }
        }
        if ($includeSite) {
            $siteTotal = $siteBase()->count();
            if ($siteTotal) $byPlatform[] = ['platform' => 'site', 'total' => $siteTotal];
        }
        usort($byPlatform, fn ($a, $b) => $b['total'] <=> $a['total']);

        // ── Per land (ISO-2) ──
        $countryMap = [];
        $addCountry = function ($rows) use (&$countryMap) {
            foreach ($rows as $r) {
                $c = $r->country ?: '??';
                $countryMap[$c] = ($countryMap[$c] ?? 0) + (int) $r->total;
            }
        };
        if ($includeApp)  $addCountry($appBase()->select('country', DB::raw('COUNT(*) as total'))->groupBy('country')->get());
        if ($includeSite) $addCountry($siteBase()->select('country', DB::raw('COUNT(*) as total'))->groupBy('country')->get());
        arsort($countryMap);
        $byCountry = [];
        foreach (array_slice($countryMap, 0, 25, true) as $code => $total) {
            $byCountry[] = ['country' => $code, 'total' => $total];
        }

        // ── Publiek: ingelogd vs gast ──
        $audience = ['user' => 0, 'guest' => 0, 'unknown' => 0];
        if ($includeApp) {
            foreach ($appBase()->select('is_authed', DB::raw('COUNT(*) as total'))->groupBy('is_authed')->get() as $r) {
                $key = is_null($r->is_authed) ? 'unknown' : ((int) $r->is_authed === 1 ? 'user' : 'guest');
                $audience[$key] += (int) $r->total;
            }
        }
        if ($includeSite) {
            // milmap.nl is inherent anoniem → altijd gast.
            $audience['guest'] += $siteBase()->count();
        }

        // ── Dag-trend ──
        $dailyMap = [];
        $addDaily = function ($rows) use (&$dailyMap) {
            foreach ($rows as $r) {
                $d = (string) $r->day;
                if (! isset($dailyMap[$d])) $dailyMap[$d] = ['day' => $d, 'routes' => 0, 'actions' => 0, 'uniques' => 0];
                $dailyMap[$d]['routes']  += (int) $r->routes;
                $dailyMap[$d]['actions'] += (int) $r->actions;
                $dailyMap[$d]['uniques'] += (int) $r->uniques;
            }
        };
        if ($includeApp) {
            $addDaily($appBase()->select(
                DB::raw('DATE(occurred_at) as day'),
                DB::raw("SUM(CASE WHEN type = 'route' THEN 1 ELSE 0 END) as routes"),
                DB::raw("SUM(CASE WHEN type = 'action' THEN 1 ELSE 0 END) as actions"),
                DB::raw('COUNT(DISTINCT visitor_hash) as uniques')
            )->groupBy('day')->get());
        }
        if ($includeSite) {
            $addDaily($siteBase()->select(
                DB::raw('DATE(occurred_at) as day'),
                DB::raw("SUM(CASE WHEN event = 'pageview' THEN 1 ELSE 0 END) as routes"),
                DB::raw("SUM(CASE WHEN event != 'pageview' THEN 1 ELSE 0 END) as actions"),
                DB::raw('COUNT(DISTINCT visitor_hash) as uniques')
            )->groupBy('day')->get());
        }
        ksort($dailyMap);
        $daily = array_values($dailyMap);

        // ── Navigatie-flow (opeenvolgende schermen) ──
        $flow = $this->flowTransitions($since, $includeApp, $includeSite, $appPlatformFilter, $until);

        // ── Herkomst (UTM) — alleen app-events dragen UTM's ──
        $utmAgg = function (string $col) use ($appBase) {
            return $appBase()->whereNotNull($col)->where($col, '!=', '')
                ->select($col . ' as k', DB::raw('COUNT(*) as total'), DB::raw('COUNT(DISTINCT visitor_hash) as uniques'))
                ->groupBy($col)->orderByDesc('total')->limit(25)->get()
                ->map(fn ($r) => ['name' => $r->k, 'total' => (int) $r->total, 'uniques' => (int) $r->uniques])->values();
        };
        $utm = $includeApp ? [
            'sources'   => $utmAgg('utm_source'),
            'mediums'   => $utmAgg('utm_medium'),
            'campaigns' => $utmAgg('utm_campaign'),
        ] : ['sources' => [], 'mediums' => [], 'campaigns' => []];

        return response()->json([
            'range'       => ['days' => $days, 'since' => $since->toIso8601String(), 'until' => $until ? $until->toIso8601String() : null],
            'platform'    => $platform,
            'totals'      => [
                'route_views'   => $routeViews,
                'action_clicks' => $actionClicks,
                'active_users'  => $activeUsers,
            ],
            'top_routes'  => $topRoutes,
            'top_actions' => $topActions,
            'by_platform' => $byPlatform,
            'by_country'  => $byCountry,
            'audience'    => $audience,
            'daily'       => $daily,
            'flow'        => $flow,
            'utm'         => $utm,
        ]);
    }

    /**
     * Voeg meerdere geaggregeerde tellijsten samen op `name` (som van total +
     * uniques, eerste niet-lege label wint), gesorteerd aflopend, afgekapt op limit.
     */
    private function mergeCounts(array $lists, int $limit): array
    {
        $merged = [];
        foreach ($lists as $list) {
            foreach ($list as $r) {
                $name = (string) $r->name;
                if (! isset($merged[$name])) {
                    $merged[$name] = ['name' => $name, 'label' => null, 'total' => 0, 'uniques' => 0];
                }
                $merged[$name]['total']   += (int) $r->total;
                $merged[$name]['uniques'] += (int) $r->uniques;
                if (empty($merged[$name]['label']) && ! empty($r->label)) {
                    $merged[$name]['label'] = $r->label;
                }
            }
        }
        usort($merged, fn ($a, $b) => $b['total'] <=> $a['total']);

        return array_slice(array_values($merged), 0, $limit);
    }

    /**
     * Top navigatie-overgangen: hoe bezoekers van scherm A naar scherm B gaan.
     * Groepeert route-bezoeken per (dag-gebonden) bezoeker-hash, sorteert
     * chronologisch (occurred_at + id als tiebreaker binnen dezelfde batch) en
     * telt opeenvolgende paren. Begrensd zodat de berekening licht blijft.
     */
    private function flowTransitions($since, bool $includeApp, bool $includeSite, ?string $appPlatformFilter, $until = null): array
    {
        $rows = [];

        if ($includeApp) {
            $q = AppEvent::where('occurred_at', '>=', $since)->where('type', 'route');
            if ($until) $q->where('occurred_at', '<=', $until);
            if ($appPlatformFilter) $q->where('platform', $appPlatformFilter);
            foreach ($q->orderByDesc('occurred_at')->limit(20000)->get(['id', 'visitor_hash', 'name', 'occurred_at']) as $e) {
                $rows[] = ['h' => $e->visitor_hash, 'n' => $e->name, 'ts' => $e->occurred_at->timestamp, 'id' => (int) $e->id];
            }
        }
        if ($includeSite) {
            $sq = SiteEvent::where('occurred_at', '>=', $since)->where('event', 'pageview');
            if ($until) $sq->where('occurred_at', '<=', $until);
            foreach ($sq->orderByDesc('occurred_at')->limit(20000)->get(['id', 'visitor_hash', 'path', 'occurred_at']) as $e) {
                $rows[] = ['h' => $e->visitor_hash, 'n' => $e->path, 'ts' => $e->occurred_at->timestamp, 'id' => (int) $e->id];
            }
        }
        if (! $rows) return [];

        // Groepeer per bezoeker.
        $byVisitor = [];
        foreach ($rows as $r) {
            $byVisitor[$r['h']][] = $r;
        }

        $pairs = [];
        foreach ($byVisitor as $seq) {
            // Chronologisch: op tijd, dan op id (batch-invoegvolgorde = klikvolgorde).
            usort($seq, fn ($a, $b) => [$a['ts'], $a['id']] <=> [$b['ts'], $b['id']]);
            $n = count($seq);
            for ($i = 0; $i < $n - 1; $i++) {
                $from = $seq[$i]['n'];
                $to   = $seq[$i + 1]['n'];
                if ($from === $to) continue; // zelfde scherm opnieuw → overslaan
                $key = $from . "\x00" . $to;
                $pairs[$key] = ($pairs[$key] ?? 0) + 1;
            }
        }
        arsort($pairs);

        $flow = [];
        foreach (array_slice($pairs, 0, 30, true) as $key => $count) {
            [$from, $to] = explode("\x00", $key);
            $flow[] = ['from' => $from, 'to' => $to, 'total' => $count];
        }

        return $flow;
    }
}
