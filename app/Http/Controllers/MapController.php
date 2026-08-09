<?php
namespace App\Http\Controllers;

use App\Models\Map;
use App\Policies\MapPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MapController extends Controller
{
    /**
     * Alle maps van ingelogde user
     */
    public function index()
    {
        return response()->json(
            Map::where('owner_id', Auth::id())
                ->latest()
                ->get()
        );
    }

    /**
     * De vorm van elke kaart: alleen de coördinaten van de waypoints, voor het
     * kaartje in de iOS-widget.
     *
     * Waarom een eigen endpoint en niet gewoon myMaps uitbreiden: die lijst
     * voedt ook de kaarten-sheet in de app, en die zou dan bij iedereen zwaarder
     * worden voor een functie die alleen de widget gebruikt.
     *
     * Bewust alleen id, label en positie — een widget van vier centimeter doet
     * niets met kleuren, iconen of notities, en alles wat hier extra in zit gaat
     * mee over de lijn én de App Group in.
     */
    public function widgetShapes()
    {
        // Meer kaarten dan iemand in een widget kan kiezen heeft geen zin; de
        // meest recente zijn de kaarten waar je mee werkt.
        $mapIds = Map::where('owner_id', Auth::id())
            ->latest()
            ->limit(self::WIDGET_MAX_MAPS)
            ->pluck('id');

        if ($mapIds->isEmpty()) {
            return response()->json(['maps' => []]);
        }

        // Eén query voor alles, daarna in PHP groeperen en aftoppen. Per kaart
        // apart bevragen zou N queries kosten voor een lijst die per definitie
        // klein moet blijven.
        $punten = \App\Models\MapWaypoint::whereIn('map_id', $mapIds)
            ->orderBy('id')
            ->get(['map_id', 'label', 'lat', 'lon'])
            ->groupBy('map_id');

        $uit = $mapIds->map(function ($id) use ($punten) {
            $lijst = ($punten[$id] ?? collect())
                ->take(self::WIDGET_MAX_POINTS)
                ->map(fn ($w) => [
                    'lat'  => (float) $w->lat,
                    'lon'  => (float) $w->lon,
                    'naam' => $w->label ?: null,
                ])
                ->values();

            return ['id' => (string) $id, 'points' => $lijst];
        });

        return response()->json(['maps' => $uit]);
    }

    /** Hoeveel kaarten en punten er hoogstens meegaan naar de widget. */
    private const WIDGET_MAX_MAPS = 25;
    private const WIDGET_MAX_POINTS = 80;

    /**
     * Extra alias voor frontend
     */
    public function myMaps()
    {
        return response()->json(
            Map::where('owner_id', Auth::id())
                ->latest()
                ->get()
        );
    }

    /**
     * Single map (owner OR collaborator OR mission collaborator)
     * Returns the map plus the caller's effective role so the frontend
     * can gate edit actions without a second request.
     */
    public function show($id)
    {
        $map = Map::findOrFail($id);

        $this->authorize('view', $map);

        $user   = Auth::user();
        $policy = new MapPolicy();
        $role   = $policy->roleFor($user, $map); // 'owner' | 'editor' | 'viewer'

        return response()->json(array_merge($map->toArray(), [
            'my_role'  => $role,
            'is_owner' => $role === 'owner',
            'can_edit' => in_array($role, ['owner', 'editor'], true),
        ]));
    }

    /**
     * Create map
     */
    public function store(Request $request)
    {
        // Gratis (uitgeproefde) accounts mogen maximaal FREE_MAP_LIMIT kaarten
        // opslaan. Tijdens de proefperiode of met een abonnement is dit onbeperkt.
        $user = $request->user();
        if ($user && ! ($user->is_admin ?? false) && ! $user->hasPremiumAccess()) {
            $ownedMaps = Map::where('owner_id', $user->id)->count();
            if ($ownedMaps >= \App\Models\User::FREE_MAP_LIMIT) {
                return response()->json([
                    'message' => 'Je gratis account kan maximaal ' . \App\Models\User::FREE_MAP_LIMIT
                        . ' kaarten opslaan. Neem een abonnement voor onbeperkte kaarten.',
                    'code'    => 'subscription_required',
                    'feature' => 'unlimited_maps',
                ], 402);
            }
        }

        $map = Map::create([
            'title' => $request->title,
            'settings' => $request->settings ?? [
                'locationformat' => 'mgrs',
                'maxZoom' => 100,
                'mapExtent' => null,
                'language' => 'nl',
                'useCurrentCoordsAsBounds' => false,
            ],
            'status' => $request->status ?? 'active',
            'owner_id' => Auth::id(),
        ]);

        return response()->json($map, 201);
    }

    /**
     * Update map (owner OR collaborator)
     */
    public function update(Request $request, $id)
    {
        $map = Map::findOrFail($id);

        // Check if user has access (via policy)
        $this->authorize('update', $map);

        // Prepare settings: merge new settings with existing ones
        $updatedData = [
            'title' => $request->title ?? $map->title,
            'status' => $request->status ?? $map->status,
        ];

        // If settings are provided, merge them with existing settings
        if ($request->has('settings')) {
            $currentSettings = is_array($map->settings) ? $map->settings : [];
            $newSettings = is_array($request->settings) ? $request->settings : [];
            $updatedData['settings'] = array_merge($currentSettings, $newSettings);
        }

        $map->update($updatedData);

        return response()->json($map);
    }

    /**
     * Delete map (owner only)
     */
    public function destroy($id)
    {
        $map = Map::findOrFail($id);

        // Check if user is owner (via policy)
        $this->authorize('delete', $map);

        $map->delete();

        return response()->json(['message' => 'Deleted']);
    }
}