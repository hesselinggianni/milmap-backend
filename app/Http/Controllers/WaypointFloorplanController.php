<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\MapWaypoint;
use App\Models\WaypointFloorplan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WaypointFloorplanController extends Controller
{
    // ── Authorization helper (mirror MapWaypointController) ───────────────────

    use \App\Http\Traits\AuthorizesMapAccess;

    private function waypointFor($mapId, $localId): MapWaypoint
    {
        return MapWaypoint::where('map_id', $mapId)
            ->where('local_id', $localId)
            ->firstOrFail();
    }

    /**
     * List floorplans (all floors) for a waypoint.
     * GET /api/v1/maps/{mapId}/waypoints/{localId}/floorplans
     */
    public function index($mapId, $localId)
    {
        $map = Map::findOrFail($mapId);
        if (! $this->authorizeMapAccess($map)) {
            abort(403, 'Geen toegang tot deze kaart.');
        }

        $waypoint = $this->waypointFor($mapId, $localId);

        $floorplans = WaypointFloorplan::where('map_waypoint_id', $waypoint->id)
            ->with('elements')
            ->orderBy('floor_index')
            ->get()
            ->map(fn($f) => $f->toClientArray());

        return response()->json(['floorplans' => $floorplans]);
    }

    /**
     * Create a new floorplan (floor) for a waypoint.
     * POST /api/v1/maps/{mapId}/waypoints/{localId}/floorplans
     * Body: { name?, floor_index, width?, height? }
     */
    public function store(Request $request, $mapId, $localId)
    {
        $map = Map::findOrFail($mapId);
        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }

        $waypoint = $this->waypointFor($mapId, $localId);

        $data = $request->validate([
            'name'        => 'nullable|string|max:200',
            'floor_index' => 'required|integer',
            'width'       => 'nullable|integer|min:100|max:8000',
            'height'      => 'nullable|integer|min:100|max:8000',
        ]);

        $floorplan = WaypointFloorplan::create([
            'map_waypoint_id' => $waypoint->id,
            'name'            => $data['name'] ?? null,
            'floor_index'     => $data['floor_index'],
            'width'           => $data['width'] ?? 1000,
            'height'          => $data['height'] ?? 700,
            'created_by'      => Auth::id(),
        ]);

        $floorplan->load('elements');

        return response()->json(['floorplan' => $floorplan->toClientArray()], 201);
    }

    /**
     * Show a single floorplan with its elements.
     * GET /api/v1/floorplans/{floorplanId}
     */
    public function show($floorplanId)
    {
        $floorplan = WaypointFloorplan::with('elements', 'waypoint')->findOrFail($floorplanId);
        $map = Map::findOrFail($floorplan->waypoint->map_id);

        if (! $this->authorizeMapAccess($map)) {
            abort(403, 'Geen toegang tot deze kaart.');
        }

        return response()->json(['floorplan' => $floorplan->toClientArray()]);
    }

    /**
     * Update floorplan meta (name, size, background).
     * PUT /api/v1/floorplans/{floorplanId}
     */
    public function update(Request $request, $floorplanId)
    {
        $floorplan = WaypointFloorplan::with('waypoint')->findOrFail($floorplanId);
        $map = Map::findOrFail($floorplan->waypoint->map_id);

        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }

        $data = $request->validate([
            'name'                   => 'nullable|string|max:200',
            'floor_index'            => 'sometimes|integer',
            'width'                  => 'sometimes|integer|min:100|max:8000',
            'height'                 => 'sometimes|integer|min:100|max:8000',
            'background_image_path'  => 'nullable|string',
        ]);

        $floorplan->update($data);
        $floorplan->load('elements');

        return response()->json(['floorplan' => $floorplan->toClientArray()]);
    }

    /**
     * Delete a floorplan (and its elements, cascade).
     * DELETE /api/v1/floorplans/{floorplanId}
     */
    public function destroy($floorplanId)
    {
        $floorplan = WaypointFloorplan::with('waypoint')->findOrFail($floorplanId);
        $map = Map::findOrFail($floorplan->waypoint->map_id);

        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }

        $floorplan->delete();

        return response()->json(['message' => 'Plattegrond verwijderd.']);
    }
}
