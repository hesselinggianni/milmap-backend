<?php

namespace App\Http\Controllers;

use App\Models\AppEvent;
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
        $data = $request->validate([
            'events'              => 'required|array|max:' . self::MAX_BATCH,
            'events.*.type'       => 'required|string|in:route,action',
            'events.*.name'       => 'required|string|max:200',
            'events.*.label'      => 'nullable|string|max:120',
            'events.*.platform'   => 'nullable|string|in:web,ios,android,desktop',
            'events.*.locale'     => 'nullable|string|max:5',
            'events.*.meta'       => 'nullable|array',
        ]);

        $ua     = (string) $request->userAgent();
        $device = preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : 'desktop';
        $hash   = hash('sha256', now()->toDateString() . config('app.key') . $request->ip() . $ua);
        $now    = now();

        $rows = [];
        foreach ($data['events'] as $e) {
            $rows[] = [
                'type'         => $e['type'],
                'name'         => $this->normalizeName($e['type'], $e['name']),
                'label'        => isset($e['label']) ? mb_substr($e['label'], 0, 120) : null,
                'platform'     => $e['platform'] ?? 'web',
                'device'       => $device,
                'locale'       => $e['locale'] ?? null,
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
        $segments = array_map(function ($seg) {
            if ($seg === '') return $seg;
            // puur numeriek, uuid, of lange hex/token → :id
            if (preg_match('/^\d+$/', $seg)) return ':id';
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $seg)) return ':id';
            if (preg_match('/^[0-9a-f]{16,}$/i', $seg)) return ':id';
            return $seg;
        }, explode('/', $name));

        return mb_substr(implode('/', $segments), 0, 200) ?: '/';
    }

    // ── Admin: aggregaties voor het analytics-dashboard ──────────────────

    public function adminSummary(Request $request): \Illuminate\Http\JsonResponse
    {
        $days  = max(1, min(365, (int) $request->query('days', 30)));
        $since = now()->subDays($days)->startOfDay();

        $base = AppEvent::where('occurred_at', '>=', $since);

        // Top-routes (meest bezochte schermen)
        $topRoutes = (clone $base)->where('type', 'route')
            ->select('name', DB::raw('COUNT(*) as total'), DB::raw('COUNT(DISTINCT visitor_hash) as uniques'))
            ->groupBy('name')
            ->orderByDesc('total')
            ->limit(50)
            ->get();

        // Top-acties (meest aangeklikte knoppen)
        $topActions = (clone $base)->where('type', 'action')
            ->select('name', DB::raw('MAX(label) as label'), DB::raw('COUNT(*) as total'), DB::raw('COUNT(DISTINCT visitor_hash) as uniques'))
            ->groupBy('name')
            ->orderByDesc('total')
            ->limit(50)
            ->get();

        // Totalen
        $routeViews  = (clone $base)->where('type', 'route')->count();
        $actionClicks = (clone $base)->where('type', 'action')->count();
        $activeUsers = (clone $base)->distinct('visitor_hash')->count('visitor_hash');

        // Per platform
        $byPlatform = (clone $base)
            ->select('platform', DB::raw('COUNT(*) as total'))
            ->groupBy('platform')
            ->orderByDesc('total')
            ->get();

        // Dag-trend (route-views + acties per dag)
        $daily = (clone $base)
            ->select(
                DB::raw('DATE(occurred_at) as day'),
                DB::raw("SUM(CASE WHEN type = 'route' THEN 1 ELSE 0 END) as routes"),
                DB::raw("SUM(CASE WHEN type = 'action' THEN 1 ELSE 0 END) as actions"),
                DB::raw('COUNT(DISTINCT visitor_hash) as uniques')
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return response()->json([
            'range' => [
                'days'  => $days,
                'since' => $since->toIso8601String(),
            ],
            'totals' => [
                'route_views'   => $routeViews,
                'action_clicks' => $actionClicks,
                'active_users'  => $activeUsers,
            ],
            'top_routes'  => $topRoutes,
            'top_actions' => $topActions,
            'by_platform' => $byPlatform,
            'daily'       => $daily,
        ]);
    }
}
