<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Status-page domain registry.
 *
 * The MilMap admin panel (app.milmap.nl) manages the list of domains that the
 * public status page (milmap.nl/status, a separate Nuxt app) should monitor.
 * Laravel is the single source of truth: the list is stored as JSON in the
 * settings table and exposed two ways:
 *
 *   • GET  /api/v1/status/domains            → public; the *enabled* domains.
 *   • GET  /api/v1/status/uptime|incidents   → public; per-domain uptime + auto
 *                                               incidents, produced by the
 *                                               backend monitor (status:monitor,
 *                                               see App\Services\StatusPageMonitor).
 *                                               milmap.nl/status reads these
 *                                               directly, so it works on the
 *                                               static (Nitro-less) deploy.
 *   • /api/v1/admin/status-domains (CRUD)    → admin-only; the panel uses this
 *                                               to add / edit / toggle / delete.
 *
 * The stored shape matches the Nuxt `StatusDomain` interface exactly so the
 * status page needs no shape changes:
 *   { id, name, url, description, enabled, createdAt }
 */
class StatusPageController extends Controller
{
    public const SETTING_KEY = 'status_page_domains';

    // ── Public ───────────────────────────────────────────────────────────

    /**
     * GET /api/v1/status/domains
     * The enabled domains, consumed by the Nuxt status page (and its monitor).
     */
    public function publicIndex()
    {
        $enabled = array_values(array_filter(
            $this->all(),
            fn ($d) => ! empty($d['enabled'])
        ));

        return response()->json($enabled);
    }

    /**
     * GET /api/v1/status/uptime
     * Per enabled domein: huidige status + uptime-balken (90 dagen). Deze data
     * wordt door de statische milmap.nl/status-pagina rechtstreeks opgehaald,
     * omdat die geen eigen Node-server heeft om te monitoren.
     */
    public function publicUptime(\App\Services\StatusPageMonitor $monitor)
    {
        return response()->json($monitor->uptimeReport());
    }

    /**
     * GET /api/v1/status/incidents
     * Publieke incidentenlijst (nieuwste eerst), voor de status-pagina.
     */
    public function publicIncidents(\App\Services\StatusPageMonitor $monitor)
    {
        return response()->json($monitor->publicIncidents());
    }

    // ── Admin ────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/status-domains
     * All domains, including disabled ones, for the management UI.
     */
    public function adminIndex()
    {
        return response()->json(['domains' => $this->all()]);
    }

    /**
     * POST /api/v1/admin/status-domains
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'url'         => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'enabled'     => ['sometimes', 'boolean'],
        ]);

        $domains  = $this->all();
        $domain = [
            'id'          => (string) Str::uuid(),
            'name'        => trim($data['name']),
            'url'         => $this->normalizeUrl($data['url']),
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'enabled'     => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : true,
            'createdAt'   => now()->toIso8601String(),
        ];

        $domains[] = $domain;
        $this->save($domains);

        return response()->json(['domain' => $domain], 201);
    }

    /**
     * PUT /api/v1/admin/status-domains/{id}
     */
    public function update(Request $request, string $id)
    {
        $domains = $this->all();
        $idx     = $this->indexOf($domains, $id);
        if ($idx === null) {
            return response()->json(['message' => 'Domein niet gevonden'], 404);
        }

        $data = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:120'],
            'url'         => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'enabled'     => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $domains[$idx]['name'] = trim($data['name']);
        }
        if (array_key_exists('url', $data)) {
            $domains[$idx]['url'] = $this->normalizeUrl($data['url']);
        }
        if (array_key_exists('description', $data)) {
            $domains[$idx]['description'] = $data['description'] !== null
                ? trim((string) $data['description'])
                : null;
        }
        if (array_key_exists('enabled', $data)) {
            $domains[$idx]['enabled'] = (bool) $data['enabled'];
        }

        $this->save($domains);

        return response()->json(['domain' => $domains[$idx]]);
    }

    /**
     * DELETE /api/v1/admin/status-domains/{id}
     */
    public function destroy(string $id)
    {
        $domains = $this->all();
        $next    = array_values(array_filter($domains, fn ($d) => ($d['id'] ?? null) !== $id));

        if (count($next) === count($domains)) {
            return response()->json(['message' => 'Domein niet gevonden'], 404);
        }

        $this->save($next);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/admin/status-domains/check
     * Live reachability probe for one URL, so the admin can verify a domain
     * before saving it. Mirrors the Nuxt monitor's thresholds (>2s = degraded).
     */
    public function check(Request $request)
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:255'],
        ]);

        $url   = $this->normalizeUrl($data['url']);
        $start = microtime(true);

        try {
            $res = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Milmap-StatusMonitor/1.0'])
                ->get($url);

            $ms       = (int) round((microtime(true) - $start) * 1000);
            $reachable = $res->successful() || $res->redirect();

            return response()->json([
                'status'       => ! $reachable ? 'down' : ($ms > 2000 ? 'degraded' : 'up'),
                'statusCode'   => $res->status(),
                'responseTime' => $ms,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status'       => 'down',
                'responseTime' => null,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** All stored domains as a clean, list-indexed array. */
    private function all(): array
    {
        $domains = Setting::get(self::SETTING_KEY, []);

        return is_array($domains) ? array_values($domains) : [];
    }

    private function save(array $domains): void
    {
        Setting::put(self::SETTING_KEY, array_values($domains));
    }

    /** Index of the domain with the given id, or null. */
    private function indexOf(array $domains, string $id): ?int
    {
        foreach ($domains as $i => $d) {
            if (($d['id'] ?? null) === $id) {
                return (int) $i;
            }
        }

        return null;
    }

    /** Trim, drop trailing slashes, default to https:// when no scheme given. */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('#/+$#', '', $url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }
}
