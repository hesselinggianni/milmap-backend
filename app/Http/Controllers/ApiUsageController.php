<?php

namespace App\Http\Controllers;

use App\Services\ApiUsageMeter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * API-verbruik van derde partijen.
 *
 *  POST /api/v1/api-usage                       ingest vanuit de app (gast of ingelogd)
 *  GET  /api/v1/admin/api-usage                 admin → Analytics → API-verbruik
 *  GET  /api/v1/admin/users/{userId}/api-usage  verbruik van één gebruiker
 *
 * Bij fouten stuurt de app ook de reden mee (`error_reasons`: korte codes als
 * http_429 of network, zie de migratie van api_usage_error_reasons); de admin
 * krijgt die als `error_reasons` terug.
 *
 * De app telt alleen DIRECTE toestel→derde-partij-calls (Open-Meteo, OSRM,
 * kaarttegels, …) en stuurt die elke ~5 min. als batch. Calls via onze eigen
 * proxy's (geo/search, tegels, static-map) telt de server zelf
 * (App\Services\ApiUsageMeter) — zo wordt niets dubbel geteld.
 *
 * Tijd is overal UTC (bucket = begin van het UTC-uur).
 */
class ApiUsageController extends Controller
{
    private const MAX_ITEMS = 50;
    private const MAX_COUNT = 50000;
    private const RANGES    = ['24h', '7d', '30d', '90d'];
    private const TOP       = 50;

    // ── Ingest ───────────────────────────────────────────────────────────

    public function ingest(Request $request): Response|JsonResponse
    {
        // Auth is optioneel: ook gasten (gedeelde kaart, uitgelogd) melden verbruik.
        $user   = auth('sanctum')->user();
        $userId = (int) ($user?->getAuthIdentifier() ?? 0);

        // Eigen throttle i.p.v. throttle:api (zie routes/api.php): per gebruiker
        // krap, per IP ruim — 400+ iPads achter één school-NAT flushen allemaal.
        $limits = (array) config('api_usage.ingest_limits', []);
        [$key, $max] = $userId
            ? ['api-usage:user:' . $userId, (int) ($limits['user'] ?? 30)]
            : ['api-usage:ip:' . ApiUsageMeter::networkHash($request->ip()), (int) ($limits['ip'] ?? 3000)];
        if (RateLimiter::tooManyAttempts($key, $max)) {
            return response()->json(['message' => 'Too Many Attempts.'], 429);
        }
        RateLimiter::hit($key, 60);

        $data = $request->validate([
            'items'            => ['required', 'array', 'min:1', 'max:' . self::MAX_ITEMS],
            'items.*.provider' => ['required', 'string', 'max:64'],
            'items.*.kind'     => ['required', 'string', 'regex:/^[a-z0-9_]{1,40}$/'],
            'items.*.requests' => ['required', 'integer', 'min:0', 'max:' . self::MAX_COUNT],
            'items.*.errors'   => ['nullable', 'integer', 'min:0', 'max:' . self::MAX_COUNT],
            // { "http_429": 3, "network": 1 } — waarom de fouten mislukten; alles optioneel (oude app-versies).
            'items.*.error_reasons'   => ['nullable', 'array', 'max:20'],
            'items.*.error_reasons.*' => ['integer', 'min:0', 'max:' . self::MAX_COUNT],
        ]);

        $bucket  = ApiUsageMeter::bucket();
        $network = ApiUsageMeter::networkHash($request->ip());

        // Dubbele (provider, kind)-paren in één batch samenvoegen: één rij per
        // (uur, provider, kind, gebruiker, netwerk) en één upsert per verzoek.
        $rows = [];
        $reasonRows = [];
        foreach ($data['items'] as $item) {
            $requests = (int) $item['requests'];
            $errors   = (int) ($item['errors'] ?? 0);
            if ($requests === 0 && $errors === 0) {
                continue;
            }
            $provider = ApiUsageMeter::provider($item['provider']);
            $k = $provider . '|' . $item['kind'];
            $rows[$k] ??= [
                'bucket' => $bucket, 'provider' => $provider, 'kind' => $item['kind'], 'source' => 'client',
                'user_id' => $userId, 'network_hash' => $network, 'requests' => 0, 'errors' => 0, 'cache_hits' => 0,
            ];
            $rows[$k]['requests'] += $requests;
            $rows[$k]['errors']   += $errors;

            // Redenen per item vastleggen; wat niet verklaard is wordt 'unknown'.
            if ($errors > 0) {
                $allocated = ApiUsageMeter::allocateReasons($errors, (array) ($item['error_reasons'] ?? []));
                foreach ($allocated as $reason => $n) {
                    $reasonRows[$k . '|' . $reason] ??= [
                        'bucket' => $bucket, 'provider' => $provider, 'kind' => $item['kind'], 'source' => 'client',
                        'user_id' => $userId, 'network_hash' => $network, 'reason' => $reason, 'count' => 0,
                    ];
                    $reasonRows[$k . '|' . $reason]['count'] += $n;
                }
            }
        }

        try {
            ApiUsageMeter::write(array_values($rows));
        } catch (Throwable $e) {
            ApiUsageMeter::reportQuietly($e);
            // 5xx: de client voegt de tellers terug in zijn buffer en probeert later opnieuw.
            return response()->json(['message' => 'Kon verbruik niet opslaan.'], 503);
        }

        // Los van de tellers: mislukt dit (bv. tabel nog niet gemigreerd), dan blijven de
        // tellers staan en probeert de client het niet opnieuw (dat zou ze dubbel tellen).
        try {
            ApiUsageMeter::writeReasons(array_values($reasonRows));
        } catch (Throwable $e) {
            ApiUsageMeter::reportQuietly($e);
        }

        return response()->noContent();
    }

    // ── Admin: overzicht ─────────────────────────────────────────────────

    public function adminSummary(Request $request): JsonResponse
    {
        $params = $request->validate([
            'range'    => ['nullable', 'in:' . implode(',', self::RANGES)],
            'provider' => ['nullable', 'string', 'max:32'],
            'user_id'  => ['nullable', 'integer', 'min:0'],
            'network'  => ['nullable', 'string', 'regex:/^[0-9a-f]{16}$/'],
        ]);

        [$range, $granularity, $from, $to] = $this->window($params['range'] ?? null);
        $provider = isset($params['provider']) && $params['provider'] !== '' ? strtolower($params['provider']) : null;
        $userId   = array_key_exists('user_id', $params) && $params['user_id'] !== null ? (int) $params['user_id'] : null;
        $network  = $params['network'] ?? null;

        $base = fn (bool $withProvider = true) => DB::table(ApiUsageMeter::TABLE)
            ->where('bucket', '>=', $from->format('Y-m-d H:i:s'))
            ->when($withProvider && $provider, fn ($q) => $q->where('provider', $provider))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($network, fn ($q) => $q->where('network_hash', $network));

        return response()->json([
            'range'        => $range,
            'from'         => $from->toIso8601ZuluString(),
            'to'           => $to->toIso8601ZuluString(),
            'timezone'     => 'UTC',
            'granularity'  => $granularity,
            'buckets'      => $this->buckets($granularity, $from, $to),
            'providers'    => $this->providers(),
            'totals'       => $this->totals($base()),
            'series'       => $this->series($base(), $granularity),
            'top_users'    => $this->topUsers($base),
            'top_networks' => $this->topNetworks($base),
            'error_reasons' => $this->errorReasons($from, $provider, $userId, $network),
            'warnings'     => $this->warnings($from, $provider, $network),
        ]);
    }

    // ── Admin: één gebruiker (bv. op de gebruikersdetailpagina) ─────────

    public function adminUser(Request $request, $userId): JsonResponse
    {
        $params = $request->validate(['range' => ['nullable', 'in:' . implode(',', self::RANGES)]]);
        abort_unless(is_numeric($userId) && (int) $userId >= 0, 404);
        $userId = (int) $userId;

        [$range, $granularity, $from, $to] = $this->window($params['range'] ?? null);

        $base = fn () => DB::table(ApiUsageMeter::TABLE)
            ->where('bucket', '>=', $from->format('Y-m-d H:i:s'))
            ->where('user_id', $userId);

        $byKind = $base()
            ->groupBy('provider', 'kind')
            ->select('provider', 'kind',
                DB::raw('SUM(requests) as requests'), DB::raw('SUM(errors) as errors'), DB::raw('SUM(cache_hits) as cache_hits'))
            ->get()
            ->map(fn ($r) => [
                'provider' => $r->provider, 'kind' => $r->kind,
                'requests' => (int) $r->requests, 'errors' => (int) $r->errors, 'cache_hits' => (int) $r->cache_hits,
            ])
            ->sortByDesc('requests')->values();

        $networks = $base()->where('network_hash', '!=', '')
            ->groupBy('network_hash')
            ->select('network_hash', DB::raw('SUM(requests) as requests'))
            ->orderByRaw('SUM(requests) DESC')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['network_hash' => $r->network_hash, 'requests' => (int) $r->requests]);

        return response()->json([
            'user'        => $this->userInfo([$userId])[$userId] ?? ['user_id' => $userId, 'name' => null, 'email' => null, 'is_guest' => $userId === 0],
            'range'       => $range,
            'from'        => $from->toIso8601ZuluString(),
            'to'          => $to->toIso8601ZuluString(),
            'timezone'    => 'UTC',
            'granularity' => $granularity,
            'buckets'     => $this->buckets($granularity, $from, $to),
            'providers'   => $this->providers(),
            'totals'      => $this->totals($base()),
            'by_kind'     => $byKind,
            'series'      => $this->series($base(), $granularity),
            'networks'    => $networks,
            'error_reasons' => $this->errorReasons($from, null, $userId),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string, 2: CarbonImmutable, 3: CarbonImmutable} */
    private function window(?string $range): array
    {
        $range = in_array($range, self::RANGES, true) ? $range : '7d';
        $now   = CarbonImmutable::now('UTC');

        if ($range === '24h') {
            return [$range, 'hour', $now->startOfHour()->subHours(23), $now];
        }

        return [$range, 'day', $now->startOfDay()->subDays((int) $range - 1), $now];
    }

    /** Alle tijdstippen in het venster (zelfde formaat als series[].t), voor nul-opvulling. */
    private function buckets(string $granularity, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        for ($t = $from; $t <= $to; $t = $granularity === 'hour' ? $t->addHour() : $t->addDay()) {
            $out[] = $this->formatT($granularity, $t->format($granularity === 'hour' ? 'Y-m-d H:00:00' : 'Y-m-d'));
        }

        return $out;
    }

    private function formatT(string $granularity, string $value): string
    {
        if ($granularity === 'day') {
            return substr($value, 0, 10);
        }

        // 'Y-m-d H:i:s' (UTC) → ISO-8601 met Z
        return str_replace(' ', 'T', substr($value, 0, 13)) . ':00:00Z';
    }

    private function providers(): array
    {
        $out = [];
        foreach ((array) config('api_usage.providers', []) as $key => $p) {
            $out[] = [
                'key'          => $key,
                'label'        => $p['label'] ?? $key,
                'scope'        => $p['scope'] ?? 'none',
                'limits'       => (object) ($p['limits'] ?? []),
                'monthly_free' => (object) ($p['monthly_free'] ?? []),
                'note'         => $p['note'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Waarom verzoeken mislukten: per dienst, soort, bron en reden het aantal
     * fouten (meeste eerst). Leeg zolang de tabel er nog niet is of er nog niets
     * vastgelegd is — fouten van vóór de introductie staan wel in `errors`.
     *
     * @return list<array{provider: string, kind: string, source: string, reason: string, count: int}>
     */
    private function errorReasons(CarbonImmutable $from, ?string $provider = null, ?int $userId = null, ?string $network = null): array
    {
        try {
            if (! Schema::hasTable(ApiUsageMeter::REASONS_TABLE)) {
                return [];
            }

            return DB::table(ApiUsageMeter::REASONS_TABLE)
                ->where('bucket', '>=', $from->format('Y-m-d H:i:s'))
                ->when($provider, fn ($q) => $q->where('provider', $provider))
                ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
                ->when($network, fn ($q) => $q->where('network_hash', $network))
                ->groupBy('provider', 'kind', 'source', 'reason')
                ->select('provider', 'kind', 'source', 'reason', DB::raw('SUM(count) as n'))
                ->orderByRaw('SUM(count) DESC')
                ->limit(200)
                ->get()
                ->map(fn ($r) => [
                    'provider' => $r->provider, 'kind' => $r->kind, 'source' => $r->source,
                    'reason' => $r->reason, 'count' => (int) $r->n,
                ])
                ->all();
        } catch (Throwable $e) {
            ApiUsageMeter::reportQuietly($e);

            return [];
        }
    }

    private function totals($query): array
    {
        return $query
            ->groupBy('provider')
            ->select('provider',
                DB::raw('SUM(requests) as requests'),
                DB::raw('SUM(errors) as errors'),
                DB::raw('SUM(cache_hits) as cache_hits'),
                DB::raw("SUM(CASE WHEN source = 'client' THEN requests ELSE 0 END) as client_requests"),
                DB::raw("SUM(CASE WHEN source = 'server' THEN requests ELSE 0 END) as server_requests"))
            ->get()
            ->map(fn ($r) => [
                'provider'        => $r->provider,
                'requests'        => (int) $r->requests,
                'errors'          => (int) $r->errors,
                'cache_hits'      => (int) $r->cache_hits,
                // Werkelijk bij de derde partij aangekomen (cache-hits niet).
                'upstream'        => max(0, (int) $r->requests - (int) $r->cache_hits),
                'client_requests' => (int) $r->client_requests,
                'server_requests' => (int) $r->server_requests,
            ])
            ->sortByDesc('requests')
            ->values()
            ->all();
    }

    private function series($query, string $granularity): array
    {
        // DATE() bestaat in zowel MySQL als SQLite (tests).
        $period = $granularity === 'hour' ? 'bucket' : 'DATE(bucket)';

        return $query
            ->selectRaw("{$period} as t, provider, SUM(requests) as requests")
            ->groupByRaw("{$period}, provider")
            ->orderByRaw($period)
            ->get()
            ->map(fn ($r) => [
                't'        => $this->formatT($granularity, (string) $r->t),
                'provider' => $r->provider,
                'requests' => (int) $r->requests,
            ])
            ->all();
    }

    private function topUsers(callable $base): array
    {
        $rows = $base()
            ->groupBy('user_id')
            ->select('user_id',
                DB::raw('SUM(requests) as requests'),
                DB::raw('SUM(errors) as errors'),
                DB::raw("COUNT(DISTINCT CASE WHEN network_hash != '' THEN network_hash END) as networks"))
            ->orderByRaw('SUM(requests) DESC')
            ->limit(self::TOP)
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $ids        = $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $byProvider = $this->byProvider($base()->whereIn('user_id', $ids), 'user_id');
        $info       = $this->userInfo($ids);

        return $rows->map(function ($r) use ($byProvider, $info) {
            $id = (int) $r->user_id;
            return ($info[$id] ?? ['user_id' => $id, 'name' => null, 'email' => null, 'is_guest' => $id === 0]) + [
                'requests'    => (int) $r->requests,
                'errors'      => (int) $r->errors,
                'networks'    => (int) $r->networks,
                'by_provider' => (object) ($byProvider[$id] ?? []),
            ];
        })->all();
    }

    private function topNetworks(callable $base): array
    {
        $rows = $base()
            ->where('network_hash', '!=', '')
            ->groupBy('network_hash')
            ->select('network_hash',
                DB::raw('SUM(requests) as requests'),
                DB::raw('COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id END) as users'),
                DB::raw('SUM(CASE WHEN user_id = 0 THEN requests ELSE 0 END) as guest_requests'))
            ->orderByRaw('SUM(requests) DESC')
            ->limit(self::TOP)
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $hashes     = $rows->pluck('network_hash')->all();
        $byProvider = $this->byProvider($base()->whereIn('network_hash', $hashes), 'network_hash');

        // Open-Meteo-piek per netwerk (per IP-limiet!): alleen directe toestel-calls.
        $openMeteo = fn (string $period) => DB::query()
            ->fromSub(
                $base(false)->whereIn('network_hash', $hashes)
                    ->where('provider', 'open_meteo')->where('source', 'client')
                    ->selectRaw("network_hash, {$period} as p, SUM(requests) as n")
                    ->groupByRaw("network_hash, {$period}"),
                'om'
            )
            ->groupBy('network_hash')
            ->select('network_hash', DB::raw('MAX(n) as max_n'))
            ->pluck('max_n', 'network_hash');
        $maxDay  = $openMeteo('DATE(bucket)');
        $maxHour = $openMeteo('bucket');

        return $rows->map(fn ($r) => [
            'network_hash'        => $r->network_hash,
            'users'               => (int) $r->users,
            'guest_requests'      => (int) $r->guest_requests,
            'requests'            => (int) $r->requests,
            'by_provider'         => (object) ($byProvider[$r->network_hash] ?? []),
            'max_day_open_meteo'  => (int) ($maxDay[$r->network_hash] ?? 0),
            'max_hour_open_meteo' => (int) ($maxHour[$r->network_hash] ?? 0),
        ])->all();
    }

    /** @return array<string|int, array<string, int>> groep → [provider → requests] */
    private function byProvider($query, string $column): array
    {
        $out = [];
        foreach ($query->groupBy($column, 'provider')->select($column, 'provider', DB::raw('SUM(requests) as requests'))->get() as $r) {
            $out[$r->{$column}][$r->provider] = (int) $r->requests;
        }
        foreach ($out as &$providers) {
            arsort($providers);
        }

        return $out;
    }

    /** @return array<int, array{user_id: int, name: ?string, email: ?string, is_guest: bool, is_blocked: bool}> */
    private function userInfo(array $ids): array
    {
        $out = [];
        if (in_array(0, $ids, true)) {
            $out[0] = ['user_id' => 0, 'name' => 'Gast', 'email' => null, 'is_guest' => true, 'is_blocked' => false];
        }
        $real = array_values(array_filter($ids));
        if ($real) {
            foreach (DB::table('users')->whereIn('id', $real)->get(['id', 'first_name', 'last_name', 'email', 'blocked_at']) as $u) {
                $out[(int) $u->id] = [
                    'user_id'  => (int) $u->id,
                    'name'     => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email,
                    'email'    => $u->email,
                    'is_guest' => false,
                    // Handmatig geblokkeerd (admin) — zo zie je in de top-lijst meteen wie al dicht staat.
                    'is_blocked' => ! is_null($u->blocked_at),
                ];
            }
        }

        return $out;
    }

    /**
     * Waarschuwingen tegen de bekende limieten uit config/api_usage.php:
     *  - per_ip: per netwerk (alleen directe toestel-calls) ≥ warn_ratio van de
     *    uur-/daglimiet, of gemiddeld boven een minuut-/secondelimiet in een uur;
     *  - global: idem over al onze server-calls samen (upstream, zonder cache-hits),
     *    bv. Nominatim gemiddeld > 1 req/s in een uur;
     *  - monthly_free: deze kalendermaand ≥ warn_ratio van het gratis tegoed.
     */
    private function warnings(CarbonImmutable $from, ?string $onlyProvider, ?string $network): array
    {
        $ratio    = (float) config('api_usage.warn_ratio', 0.8);
        $warnings = [];

        foreach ((array) config('api_usage.providers', []) as $key => $p) {
            if ($onlyProvider && $onlyProvider !== $key) {
                continue;
            }
            $label  = $p['label'] ?? $key;
            $scope  = $p['scope'] ?? 'none';
            $limits = (array) ($p['limits'] ?? []);

            if (in_array($scope, ['per_ip', 'global'], true)) {
                foreach ($this->checks($limits, $ratio) as $check) {
                    $period = $check['period'] === 'hour' ? 'bucket' : 'DATE(bucket)';
                    $q = fn () => DB::table(ApiUsageMeter::TABLE)
                        ->where('bucket', '>=', $from->format('Y-m-d H:i:s'))
                        ->where('provider', $key);

                    $queries = [];
                    // per_ip: elk client-netwerk heeft z'n eigen budget (directe toestel-calls)…
                    if ($scope === 'per_ip') {
                        $queries[] = $q()->where('source', 'client')->where('network_hash', '!=', '')
                            ->when($network, fn ($w) => $w->where('network_hash', $network))
                            ->selectRaw("network_hash, {$period} as p, SUM(requests) as n")
                            ->groupByRaw("network_hash, {$period}");
                    }
                    // …en alles wat via onze proxy gaat deelt het budget van ons server-IP
                    // (upstream = zonder cache-hits). Bij global is dat de enige toets.
                    if (! $network) {
                        $queries[] = $q()->where('source', 'server')
                            ->selectRaw("'' as network_hash, {$period} as p, SUM(requests) - SUM(cache_hits) as n")
                            ->groupByRaw($period);
                    }

                    // Per netwerk (en voor de server) alleen de hoogste overschrijding melden.
                    $worst = [];
                    foreach ($queries as $query) {
                        foreach ($query->get() as $r) {
                            $n = (int) $r->n;
                            if ($n < $check['threshold']) {
                                continue;
                            }
                            if (! isset($worst[$r->network_hash]) || $n > $worst[$r->network_hash]['value']) {
                                $worst[$r->network_hash] = ['value' => $n, 'at' => (string) $r->p];
                            }
                        }
                    }

                    foreach ($worst as $hash => $w) {
                        $hash = (string) $hash;
                        $pct = $check['limit'] > 0 ? $w['value'] / $check['limit'] : 0;
                        $wie = $hash !== '' ? 'Netwerk ' . $hash : 'Onze server';
                        $periode = $check['period'] === 'hour' ? 'in één uur' : 'op één dag';
                        $warnings[] = array_filter([
                            'provider'     => $key,
                            'network_hash' => $hash !== '' ? $hash : null,
                            'source'       => $hash !== '' ? 'client' : 'server',
                            'period'       => $check['period'],
                            'at'           => $this->formatT($check['period'] === 'hour' ? 'hour' : 'day', $w['at']),
                            'value'        => $w['value'],
                            'limit'        => $check['limit'],
                            'level'        => $pct >= 1 ? 'critical' : 'warning',
                            'message'      => sprintf('%s: %s %s-requests %s (%d%% van %s).',
                                $wie, $this->nl($w['value']), $label, $periode, (int) round($pct * 100), $check['label']),
                        ], fn ($v) => $v !== null);
                    }
                }
            }

            // Gratis maandtegoed (Mapbox e.d.): deze UTC-kalendermaand, upstream.
            $free = (array) ($p['monthly_free'] ?? []);
            if ($free) {
                $month = CarbonImmutable::now('UTC')->startOfMonth();
                $used = DB::table(ApiUsageMeter::TABLE)
                    ->where('bucket', '>=', $month->format('Y-m-d H:i:s'))
                    ->where('provider', $key)
                    ->whereIn('kind', array_keys($free))
                    ->groupBy('kind')
                    ->select('kind', DB::raw('SUM(requests) - SUM(cache_hits) as n'))
                    ->pluck('n', 'kind');
                foreach ($free as $kind => $allowance) {
                    $n = (int) ($used[$kind] ?? 0);
                    if ($allowance > 0 && $n >= $ratio * $allowance) {
                        $pct = $n / $allowance;
                        $warnings[] = [
                            'provider' => $key,
                            'kind'     => $kind,
                            'period'   => 'month',
                            'at'       => $month->format('Y-m'),
                            'value'    => $n,
                            'limit'    => (int) $allowance,
                            'level'    => $pct >= 1 ? 'critical' : 'warning',
                            'message'  => sprintf('%s %s deze maand: %s van %s gratis (%d%%).',
                                $label, $kind, $this->nl($n), $this->nl($allowance), (int) round($pct * 100)),
                        ];
                    }
                }
            }
        }

        usort($warnings, fn ($a, $b) => [$b['level'] === 'critical', $b['value'] / max(1, $b['limit'])]
            <=> [$a['level'] === 'critical', $a['value'] / max(1, $a['limit'])]);

        return array_slice($warnings, 0, 100);
    }

    /**
     * Te controleren drempels per periode. Uur-/daglimieten waarschuwen vanaf
     * warn_ratio; minuut-/secondelimieten zijn met uur-buckets alleen als
     * gemiddelde te toetsen (gemiddeld erboven = zeker overschreden).
     *
     * @return list<array{period: string, threshold: float, limit: int, label: string}>
     */
    private function checks(array $limits, float $ratio): array
    {
        $checks = [];
        if (! empty($limits['day'])) {
            $checks[] = ['period' => 'day', 'threshold' => $ratio * $limits['day'], 'limit' => (int) $limits['day'], 'label' => $this->nl($limits['day']) . '/dag'];
        }
        if (! empty($limits['hour'])) {
            $checks[] = ['period' => 'hour', 'threshold' => $ratio * $limits['hour'], 'limit' => (int) $limits['hour'], 'label' => $this->nl($limits['hour']) . '/uur'];
        } elseif (! empty($limits['minute'])) {
            $perHour = (int) $limits['minute'] * 60;
            $checks[] = ['period' => 'hour', 'threshold' => $perHour, 'limit' => $perHour, 'label' => 'gemiddeld ' . $this->nl($limits['minute']) . '/min'];
        } elseif (! empty($limits['second'])) {
            $perHour = (int) $limits['second'] * 3600;
            $checks[] = ['period' => 'hour', 'threshold' => $perHour, 'limit' => $perHour, 'label' => 'gemiddeld ' . $this->nl($limits['second']) . '/s'];
        }

        return $checks;
    }

    private function nl(int|float $n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }
}
