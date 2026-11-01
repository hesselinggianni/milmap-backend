<?php

namespace App\Http\Controllers;

use App\Models\PublicProfile;
use App\Models\RouteMap;
use App\Models\TrailRoute;
use App\Support\PublicRoutePresenter;
use App\Support\RouteGeometry;
use App\Support\TrailRoutePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Publieke routecatalogus voor milmap.nl: ALLE openbare routes (geïmporteerde
 * OSM-routes + gepubliceerde routes van gebruikers) als platte, gepagineerde
 * lijst. Bedoeld voor build-time prerender, sitemap en het overzicht op de site —
 * dus geen kaartvak nodig zoals bij /routes/discover.
 *
 * Elke regel draagt `slug` (uniek over beide soorten heen) en `ref`: waar de
 * detail-API te vinden is ('osm' → /trail-routes/{slug}, 'user' → /profiles/{handle}/routes/{slug}).
 */
class RouteCatalogController extends Controller
{
    private const PREVIEW_POINTS = 20;

    /** GET /api/v1/routes/catalog?page=1&per_page=100&kind=osm|user */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'kind' => ['nullable', 'in:osm,user'],
        ]);
        $perPage = (int) ($data['per_page'] ?? 100);
        $page = (int) ($data['page'] ?? 1);
        $kind = $data['kind'] ?? null;

        // OSM-routes eerst, daarna gebruikersroutes: één doorlopende, stabiele volgorde.
        $osmQuery = TrailRoute::published()->orderBy('id');
        $osmTotal = $kind === 'user' ? 0 : (clone $osmQuery)->count();
        $userQuery = $this->userQuery();
        $userTotal = $kind === 'osm' ? 0 : (clone $userQuery)->count();
        $total = $osmTotal + $userTotal;

        $offset = ($page - 1) * $perPage;
        $items = [];

        if ($offset < $osmTotal) {
            $routes = (clone $osmQuery)->skip($offset)->take($perPage)->get();
            TrailRoutePresenter::fillMissingPreviews($routes);
            $provinces = $routes->pluck('province', 'slug');
            foreach (TrailRoutePresenter::summaries($routes) as $r) {
                $items[] = $this->slimOsm($r, $provinces[$r['slug']] ?? null);
            }
        }
        $left = $perPage - count($items);
        if ($left > 0 && $userTotal > 0) {
            $userOffset = max(0, $offset - $osmTotal);
            $routes = (clone $userQuery)->skip($userOffset)->take($left)->get();
            $handles = PublicProfile::whereIn('user_id', $routes->pluck('owner_id')->unique())->get()->keyBy('user_id');
            foreach (PublicRoutePresenter::routeSummaries($routes) as $i => $r) {
                $p = $handles[$routes[$i]->owner_id] ?? null;
                if ($p) {
                    $items[] = $this->slimUser($r, $p);
                }
            }
        }

        return response()->json([
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ])->header('Cache-Control', 'public, max-age=600');
    }

    private function userQuery()
    {
        // Alleen routes van zichtbare profielen (zelfde regels als het openbare profiel).
        $visible = PublicProfile::visible()->pluck('user_id');

        return RouteMap::query()
            ->where('is_public', true)
            ->whereNull('connect_organization_id')
            ->whereIn('owner_id', $visible)
            ->orderBy('id');
    }

    private function slimOsm(array $r, ?string $province = null): array
    {
        return [
            'kind' => 'osm',
            'slug' => $r['slug'],
            'handle' => null,
            'title' => $r['title'],
            'sport' => $r['sport'] ?? null,
            'distance_m' => (int) round((float) ($r['total_distance'] ?? 0)),
            'ascent_m' => $r['ascent_m'] ?? null,
            'province' => $province,
            'is_loop' => (bool) ($r['is_loop'] ?? false),
            'author' => $r['author']['name'] ?? 'OpenStreetMap',
            'updated_at' => $r['published_at'] ?? null,
            'preview' => $this->shrink($r['preview'] ?? []), // polyline5
        ];
    }

    private function slimUser(array $r, PublicProfile $p): array
    {
        return [
            'kind' => 'user',
            'slug' => $r['slug'],
            'handle' => $p->handle,
            'title' => $r['title'],
            'sport' => $r['sport'] ?? null,
            'distance_m' => (int) round((float) ($r['total_distance'] ?? 0)),
            'ascent_m' => $r['ascent_m'] ?? null,
            'province' => null,
            'is_loop' => false,
            'author' => $p->publicName(),
            'updated_at' => $r['published_at'] ?? null,
            'preview' => $this->shrink($r['preview'] ?? []), // polyline5
        ];
    }

    /** Voorbeeldlijn als gecodeerde polyline (precisie 5, ±20 punten): ~40 tekens i.p.v. ~300 aan JSON. */
    private function shrink(array $pts): string
    {
        $pts = PublicRoutePresenter::sample($pts, self::PREVIEW_POINTS);

        return count($pts) >= 2 ? RouteGeometry::encode($pts) : '';
    }
}
