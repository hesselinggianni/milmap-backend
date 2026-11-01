<?php

namespace App\Http\Controllers;

use App\Models\TrailRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin → Routes per land: hoeveel geïmporteerde OSM-routes (trail_routes) er per
 * land en per type zijn, en hoe vaak gebruikers ze als eigen route hebben opgeslagen.
 * Eén GROUP BY per tabel; de tabellen zijn klein genoeg om niet te cachen.
 */
class AdminTrailRouteController extends Controller
{
    /** Alle typen die de importer kent: ook zonder routes tonen we ze, zodat de kolommen niet verspringen. */
    private const TYPES = ['wandelen', 'fietsen', 'mtb', 'hardlopen'];

    private const TOP_SAVED = 10;

    public function stats()
    {
        $rows = TrailRoute::query()
            ->selectRaw('country, type, is_published, COUNT(*) as routes, COALESCE(SUM(distance_km), 0) as km')
            ->groupBy('country', 'type', 'is_published')
            ->get();

        // Bekende typen vast vooraan, onverwachte uit de data erachter.
        $types = array_values(array_unique(array_merge(
            self::TYPES,
            $rows->pluck('type')->unique()->sort()->values()->all(),
        )));

        $countries = [];
        $totals = $this->emptyCell($types);

        foreach ($rows as $row) {
            $code = strtoupper((string) $row->country);
            $countries[$code] ??= ['code' => $code] + $this->emptyCell($types);

            $this->add($countries[$code], $row);
            $this->add($countries[$code]['types'][$row->type], $row);
            $this->add($totals, $row);
            $this->add($totals['types'][$row->type], $row);
        }

        // "Opgeslagen" = een nog bestaande eigen route die is gemaakt via "opslaan" op een OSM-route.
        $saves = $this->saveCounts();
        $saved = $saves
            ? TrailRoute::query()->whereIn('id', array_keys($saves))
                ->get(['id', 'slug', 'name', 'type', 'country', 'province', 'distance_km', 'source_url'])
            : collect();

        foreach ($saved as $route) {
            $n = $saves[$route->id];
            $code = strtoupper((string) $route->country);

            $countries[$code]['saves'] += $n;
            $countries[$code]['types'][$route->type]['saves'] += $n;
            $totals['saves'] += $n;
            $totals['types'][$route->type]['saves'] += $n;
        }

        $countries = collect($countries)
            ->map(fn ($country) => $this->rounded($country))
            ->sortByDesc('routes')
            ->values()
            ->all();

        $topSaved = $saved
            ->map(fn ($route) => [
                'id' => $route->id,
                'slug' => $route->slug,
                'name' => $route->name,
                'type' => $route->type,
                'country' => strtoupper((string) $route->country),
                'province' => $route->province,
                'distance_km' => $route->distance_km,
                'source_url' => $route->source_url,
                'saves' => $saves[$route->id],
            ])
            ->sortBy([['saves', 'desc'], ['name', 'asc']])
            ->take(self::TOP_SAVED)
            ->values()
            ->all();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'types' => $types,
            'countries' => $countries,
            'totals' => $this->rounded($totals),
            'top_saved' => $topSaved,
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    /**
     * Aantal opgeslagen kopieën per OSM-route: [trail_route-id => aantal]. Een kopie is een
     * routekaart met cloned_from_type = trail_route (zie PublicRouteController::cloneTrailRoute);
     * weggegooide kopieën tellen niet mee. Leeg zolang die kolommen/functie er nog niet zijn.
     */
    private function saveCounts(): array
    {
        if (! Schema::hasTable('route_maps') || ! Schema::hasColumns('route_maps', ['cloned_from_type', 'cloned_from_id'])) {
            return [];
        }

        $query = DB::table('route_maps')
            ->where('cloned_from_type', 'trail_route')
            ->selectRaw('cloned_from_id, COUNT(*) as saves')
            ->groupBy('cloned_from_id');

        if (Schema::hasColumn('route_maps', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->pluck('saves', 'cloned_from_id')->map(fn ($n) => (int) $n)->all();
    }

    /** Teller per land (of totaal) met daaronder dezelfde teller per type. */
    private function emptyCell(array $types): array
    {
        $cell = ['routes' => 0, 'hidden' => 0, 'km' => 0.0, 'saves' => 0];

        return $cell + ['types' => array_fill_keys($types, $cell)];
    }

    /** Gepubliceerde routes tellen als "routes" (en in km); ongepubliceerde apart als "hidden". */
    private function add(array &$cell, $row): void
    {
        if ($row->is_published) {
            $cell['routes'] += (int) $row->routes;
            $cell['km'] += (float) $row->km;
        } else {
            $cell['hidden'] += (int) $row->routes;
        }
    }

    private function rounded(array $cell): array
    {
        $cell['km'] = round($cell['km'], 1);
        if (isset($cell['types'])) {
            $cell['types'] = array_map(fn ($sub) => $this->rounded($sub), $cell['types']);
        }

        return $cell;
    }
}
