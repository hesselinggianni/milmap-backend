<?php

namespace App\Http\Controllers;

use App\Events\MapWaypointChanged;
use App\Models\Map;
use App\Models\MapWaypoint;
use App\Models\MapWaypointImage;
use App\Models\UserUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        $waypoints = MapWaypoint::where('map_id', $mapId)->with('images')->get()
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
            // Militair waypoint-type (start/end/rally/erv/hlz/ccp/op/checkpoint) —
            // vrij string-veld, zie migratie 2026_08_08_140000.
            'type'     => 'nullable|string|max:30',
            'note'     => 'nullable|string',
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
                'type'    => $data['type'] ?? null,
                'note'    => $data['note'] ?? null,
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
            'type'  => 'nullable|string|max:30',
            'note'  => 'nullable|string',
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

    // ── Waypoint-foto's ───────────────────────────────────────────────────────

    private const MAX_IMAGES = 3;

    /**
     * Upload een foto bij een waypoint. Maakt het waypoint aan als het nog niet
     * server-side bestaat (nodig zodat lokale kaarten die net gesynct worden ook
     * een foto kunnen dragen). Body (multipart): image, + optioneel lon/lat/…
     * POST /api/v1/maps/{mapId}/waypoints/{localId}/images
     */
    public function uploadImage(Request $request, $mapId, $localId)
    {
        $map = Map::findOrFail($mapId);

        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }

        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,webp|max:10240', // 10MB
            'lon'   => 'sometimes|numeric',
            'lat'   => 'sometimes|numeric',
            'mgrs'  => 'nullable|string|max:20',
            'label' => 'nullable|string|max:200',
            'color' => 'nullable|string|max:20',
            'icon'  => 'nullable|string|max:30',
        ]);

        // Waypoint moet bestaan; maak het aan als de kaart net server-side wordt
        // (upsert op local_id) zodat foto's ook op nog-lokale kaarten werken.
        $waypoint = MapWaypoint::firstOrNew([
            'map_id'   => $mapId,
            'local_id' => (int) $localId,
        ]);
        if (! $waypoint->exists) {
            $waypoint->fill([
                'user_id' => Auth::id(),
                'lon'     => $request->input('lon', 0),
                'lat'     => $request->input('lat', 0),
                'mgrs'    => $request->input('mgrs'),
                'label'   => $request->input('label'),
                'color'   => $request->input('color', '#2b7fff'),
                'icon'    => $request->input('icon', 'pin'),
            ])->save();
        }

        if ($waypoint->images()->count() >= self::MAX_IMAGES) {
            abort(422, 'Maximaal ' . self::MAX_IMAGES . ' foto\'s per waypoint.');
        }

        $file = $request->file('image');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = Str::uuid() . '.' . $ext;
        $path = $file->storeAs("waypoints/{$waypoint->id}", $name, 'public');

        $image = $waypoint->images()->create([
            'user_id'       => Auth::id(),
            'path'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getClientMimeType(),
            'size'          => $file->getSize(),
        ]);

        UserUpload::record(Auth::id(), $path, (int) $file->getSize(), $file->getClientMimeType(), 'waypoint');

        $waypoint->load('images');
        broadcast(new MapWaypointChanged($mapId, $waypoint->toClientArray(), 'updated', Auth::id()))->toOthers();

        return response()->json([
            'image'     => $image->toApiArray(),
            'waypoint'  => $waypoint->toClientArray(),
        ], 201);
    }

    /**
     * Verwijder een waypoint-foto.
     * DELETE /api/v1/maps/{mapId}/waypoints/{localId}/images/{imageId}
     */
    public function deleteImage($mapId, $localId, $imageId)
    {
        $map = Map::findOrFail($mapId);

        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }

        $waypoint = MapWaypoint::where('map_id', $mapId)
            ->where('local_id', $localId)
            ->firstOrFail();

        $image = $waypoint->images()->where('id', $imageId)->firstOrFail();

        Storage::disk('public')->delete($image->path);
        $image->delete();

        $waypoint->load('images');
        broadcast(new MapWaypointChanged($mapId, $waypoint->toClientArray(), 'updated', Auth::id()))->toOthers();

        return response()->json(['waypoint' => $waypoint->toClientArray()]);
    }
}
