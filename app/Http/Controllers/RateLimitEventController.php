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
        $q = DB::table(RateLimitLog::TABLE.' as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.last_seen_at', '>=', now()->subDays($days));

        if (in_array($request->query('source'), ['internal', 'external'], true)) {
            $q->where('e.source', $request->query('source'));
        }

        $rows = $q->orderByDesc('e.last_seen_at')->limit(500)->get([
            'e.id', 'e.source', 'e.provider', 'e.method', 'e.route', 'e.limiter', 'e.user_id',
            'u.name as user_name', 'u.email as user_email', 'e.ip', 'e.installation', 'e.platform',
            'e.user_agent', 'e.max_attempts', 'e.retry_after', 'e.hits', 'e.first_seen_at', 'e.last_seen_at',
        ]);

        $labels = collect(config('api_usage.providers', []))->map(fn ($p) => $p['label'] ?? null);
        $rows = $rows->map(function ($r) use ($labels) {
            $r->provider_label = $r->provider ? ($labels[$r->provider] ?? $r->provider) : null;
            return $r;
        });

        return response()->json([
            'events' => $rows,
            'totals' => [
                'events' => $rows->count(),
                'hits'   => (int) $rows->sum('hits'),
                'users'  => $rows->pluck('user_id')->filter()->unique()->count(),
                'ips'    => $rows->pluck('ip')->filter()->unique()->count(),
            ],
        ]);
    }

    /** DELETE /api/v1/admin/rate-limit-events */
    public function adminClear()
    {
        return response()->json(['ok' => true, 'verwijderd' => DB::table(RateLimitLog::TABLE)->delete()]);
    }
}
