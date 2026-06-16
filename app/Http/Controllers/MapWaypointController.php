<?php

namespace App\Http\Controllers;

use App\Events\MapWaypointChanged;
use App\Models\Map;
use App\Models\MapWaypoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MapWaypointController extends Controller
{
    // ── Authorization helper ─────────────────────────────────────────────────

    private function authorizeMapAccess(Map $map, bool $editorRequired = false): bool
    {
        $userId = Auth::id();
        if ((int) $map->owner_id === $userId) return true;

        $collaborator = $map->collaborators()
            ->where('user_id', $userId)
            ->where('status', 'accepted')
            ->first();

        if (! $collaborator) return false;
        if ($editorRequired && $collaborator->role === 'viewer') return false;

        return true;
    }

    /**
     * List all waypoints for a map (owner or accepted collaborator).
     * GET /api/v1/maps/{mapId}/waypoints
     */
    public function index($mapId)
    {
        $map = Map::findOrFail($mapId);

        if (! $this->authorizeMapAccess($map)) {
            abort(403, 'Geen toegang tot deze kaart.');
        }

        $waypoints = MapWaypoint::where('map_id', $mapId)->get()
            ->map(fn($w) => $w->toClientArray());

        return response()->json(['waypoints' => $waypoints]);
    }

    /**
     * Create a waypoint and broadcast to collaborators.
     * POST /api/v1/maps/{mapId}/waypoints
     * Body: { local_id, lon, lat, mgrs?, label?, color?, icon? }
     */
    public function store(Request $request, $mapId)
    {
        $map = Map::findOrFail($mapId);

        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }

        $data = $request->validate([
            'local_id' => 'required|integer',
            'lon'      => 'required|numeric',
            'lat'      => 'required|numeric',
            'mgrs'     => 'nullable|string|max:20',
            'label'    => 'nullable|string|max:200',
            'color'    => 'nullable|string|max:20',
            'icon'     => 'nullable|string|max:30',
        ]);

        $waypoint = MapWaypoint::updateOrCreate(
            ['map_id' => $mapId, 'local_id' => $data['local_id']],
            [
                'user_id' => Auth::id(),
                'lon'     => $data['lon'],
                'lat'     => $data['lat'],
                'mgrs'    => $data['mgrs'] ?? null,
                'label'   => $data['label'] ?? null,
                'color'   => $data['color'] ?? '#2b7fff',
                'icon'    => $data['icon'] ?? 'pin',
            ]
        );

        broadcast(new MapWaypointChanged($mapId, $waypoint->toClientArray(), 'created', Auth::id()))->toOthers();

        return response()->json(['waypoint' => $waypoint->toClientArray()], 201);
    }

    /**
     * Update a waypoint by local_id and broadcast.
     * PUT /api/v1/maps/{mapId}/waypoints/{localId}
     */
    public function update(Request $request, $mapId, $localId)
    {
        $map = Map::findOrFail($mapId);

        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }

        $waypoint = MapWaypoint::where('map_id', $mapId)
            ->where('local_id', $localId)
            ->firstOrFail();

        $data = $request->validate([
            'lon'   => 'sometimes|numeric',
            'lat'   => 'sometimes|numeric',
            'mgrs'  => 'nullable|string|max:20',
            'label' => 'nullable|string|max:200',
            'color' => 'nullable|string|max:20',
            'icon'  => 'nullable|string|max:30',
        ]);

        $waypoint->update($data);

        broadcast(new MapWaypointChanged($mapId, $waypoint->toClientArray(), 'updated', Auth::id()))->toOthers();

        return response()->json(['waypoint' => $waypoint->toClientArray()]);
    }

    /**
     * Soft-delete a waypoint and broadcast.
     * DELETE /api/v1/maps/{mapId}/waypoints/{localId}
     */
    public function destroy($mapId, $localId)
    {
        $map = Map::findOrFail($mapId);

        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }

        $waypoint = MapWaypoint::where('map_id', $mapId)
            ->where('local_id', $localId)
            ->firstOrFail();

        $clientData = $waypoint->toClientArray();
        $waypoint->delete();

        broadcast(new MapWaypointChanged($mapId, $clientData, 'deleted', Auth::id()))->toOthers();

        return response()->json(['message' => 'Waypoint verwijderd.']);
    }
}
