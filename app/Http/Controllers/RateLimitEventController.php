<?php

namespace App\Http\Controllers;

use App\Services\ApiUsageMeter;
use App\Services\RateLimitLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RateLimitEventController extends Controller
{
    /** POST /api/v1/rate-limit-events — de app meldt een 429 van een externe API. */
    public function ingest(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|string|max:40',
            'kind'     => 'nullable|string|max:40',
            'platform' => 'nullable|string|max:20',
            'retry_after' => 'nullable|integer|min:0|max:86400',
        ]);

        RateLimitLog::record('external', $request, [
            'provider'    => ApiUsageMeter::provider($data['provider']),
            'route'       => isset($data['kind']) ? ApiUsageMeter::kind($data['kind']) : null,
            'platform'    => $data['platform'] ?? null,
            'retry_after' => $data['retry_after'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }

    /** GET /api/v1/admin/rate-limit-events?source=&days= */
    public function adminIndex(Request $request)
    {
        $days = max(1, min(90, (int) $request->query('days', 7)));
        $source = in_array($request->query('source'), ['internal', 'external'], true) ? $request->query('source') : null;

        // Basisfilter, opnieuw te gebruiken voor de lijst en de samenvattingen.
        $base = fn () => DB::table(RateLimitLog::TABLE.' as e')
            ->where('e.last_seen_at', '>=', now()->subDays($days))
            ->when($source, fn ($q) => $q->where('e.source', $source));

        $rows = $base()->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->orderByDesc('e.last_seen_at')->limit(500)->get([
                'e.id', 'e.source', 'e.provider', 'e.method', 'e.route', 'e.limiter', 'e.reason', 'e.user_id',
                'u.first_name', 'u.last_name', 'u.email as user_email', 'e.ip', 'e.installation', 'e.platform',
                'e.user_agent', 'e.max_attempts', 'e.retry_after', 'e.hits', 'e.first_seen_at', 'e.last_seen_at',
            ]);

        $labels = collect(config('api_usage.providers', []))->map(fn ($p) => $p['label'] ?? null);
        $rows = $rows->map(function ($r) use ($labels) {
            $r->provider_label = $r->provider ? ($labels[$r->provider] ?? $r->provider) : null;
            $r->user_name = self::fullName($r->first_name, $r->last_name);
            unset($r->first_name, $r->last_name);
            return $r;
        });

        // Welke aanvragen worden het vaakst geblokkeerd, en door wie. Eigen query's
        // (niet op de 500 rijen hierboven) zodat de top niet door het limiet scheef trekt.
        $byRoute = $base()->groupBy('e.source', 'e.provider', 'e.method', 'e.route')
            ->selectRaw('e.source, e.provider, e.method, e.route, SUM(e.hits) as hits, COUNT(DISTINCT e.user_id) as users, COUNT(DISTINCT e.ip) as ips, MAX(e.reason) as reason, MAX(e.limiter) as limiter, MAX(e.max_attempts) as max_attempts, MAX(e.last_seen_at) as last_seen_at')
            ->orderByDesc('hits')->limit(25)->get()
            ->map(function ($r) use ($labels) {
                $r->provider_label = $r->provider ? ($labels[$r->provider] ?? $r->provider) : null;
                $r->hits = (int) $r->hits; $r->users = (int) $r->users; $r->ips = (int) $r->ips;
                return $r;
            });

        $byUser = $base()->join('users as u', 'u.id', '=', 'e.user_id')->groupBy('e.user_id', 'u.first_name', 'u.last_name', 'u.email')
            ->selectRaw('e.user_id, u.first_name, u.last_name, u.email as user_email, SUM(e.hits) as hits, COUNT(DISTINCT e.route) as routes, MAX(e.last_seen_at) as last_seen_at')
            ->orderByDesc('hits')->limit(25)->get()
            ->map(function ($r) use ($base) {
                // De aanvraag waar deze gebruiker het vaakst tegenaan loopt, met reden.
                $top = $base()->where('e.user_id', $r->user_id)->orderByDesc('e.hits')->first(['e.method', 'e.route', 'e.provider', 'e.reason']);
                $r->top_route = $top ? trim(($top->method ?: '').' '.($top->route ?: $top->provider)) : null;
                $r->top_reason = $top->reason ?? null;
                $r->hits = (int) $r->hits; $r->routes = (int) $r->routes;
                $r->user_name = self::fullName($r->first_name, $r->last_name);
                unset($r->first_name, $r->last_name);
                return $r;
            });

        $byIp = $base()->whereNull('e.user_id')->whereNotNull('e.ip')->groupBy('e.ip')
            ->selectRaw('e.ip, SUM(e.hits) as hits, COUNT(DISTINCT e.route) as routes, MAX(e.last_seen_at) as last_seen_at, MAX(e.reason) as reason')
            ->orderByDesc('hits')->limit(15)->get()
            ->map(function ($r) { $r->hits = (int) $r->hits; $r->routes = (int) $r->routes; return $r; });

        $totals = $base()->selectRaw('COUNT(*) as events, COALESCE(SUM(e.hits), 0) as hits, COUNT(DISTINCT e.user_id) as users, COUNT(DISTINCT e.ip) as ips')->first();

        return response()->json([
            'events'   => $rows,
            'by_route' => $byRoute,
            'by_user'  => $byUser,
            'by_ip'    => $byIp,
            'totals'   => [
                'events' => (int) ($totals->events ?? 0),
                'hits'   => (int) ($totals->hits ?? 0),
                'users'  => (int) ($totals->users ?? 0),
                'ips'    => (int) ($totals->ips ?? 0),
            ],
        ]);
    }

    private static function fullName(?string $first, ?string $last): ?string
    {
        return trim(($first ?? '').' '.($last ?? '')) ?: null;
    }

    /** DELETE /api/v1/admin/rate-limit-events */
    public function adminClear()
    {
        return response()->json(['ok' => true, 'verwijderd' => DB::table(RateLimitLog::TABLE)->delete()]);
    }
}
