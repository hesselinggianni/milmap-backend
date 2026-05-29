<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\MapShare;
use App\Models\RouteMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MapShareController extends Controller
{
    /**
     * List shares for a map
     */
    public function index($mapId)
    {
        $map = Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        $shares = MapShare::where('map_id', $map->id)
            ->latest()
            ->get();

        return response()->json($shares);
    }

    /**
     * Create a share link
     */
    public function store(Request $request, $mapId)
    {
        $map = Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        $request->validate([
            'route_map_ids' => 'nullable|array',
            'settings' => 'nullable|array',
            'title' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date',
        ]);

        $share = MapShare::create([
            'map_id' => $map->id,
            'created_by' => Auth::id(),
            'route_map_ids' => $request->route_map_ids,
            'settings' => $request->settings ?? [
                'showTable' => true,
                'showMarkers' => true,
                'showLines' => true,
            ],
            'title' => $request->title ?? $map->title,
            'expires_at' => $request->expires_at,
        ]);

        return response()->json($share, 201);
    }

    /**
     * Delete a share link
     */
    public function destroy($mapId, $shareId)
    {
        $map = Map::where('id', $mapId)
            ->where('owner_id', Auth::id())
            ->firstOrFail();

        $share = MapShare::where('id', $shareId)
            ->where('map_id', $map->id)
            ->firstOrFail();

        $share->delete();

        return response()->json(null, 204);
    }

    /**
     * Public: get shared map data by token (no auth required)
     */
    public function showByToken($token)
    {
        $share = MapShare::active()
            ->where('token', $token)
            ->firstOrFail();

        $map = $share->map;

        // Load route maps (filtered by share config)
        $routeMapsQuery = RouteMap::where('map_id', $map->id);

        if (!empty($share->route_map_ids)) {
            $routeMapsQuery->whereIn('id', $share->route_map_ids);
        }

        $routeMaps = $routeMapsQuery->get();

        return response()->json([
            'share' => [
                'id' => $share->id,
                'title' => $share->title,
                'token' => $share->token,
                'settings' => $share->settings,
                'expires_at' => $share->expires_at,
            ],
            'map' => $map,
            'routeMaps' => $routeMaps,
        ]);
    }
}
