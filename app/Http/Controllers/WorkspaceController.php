<?php

namespace App\Http\Controllers;

use App\Http\Traits\AuthorizesMapAccess;
use App\Models\Map;
use App\Models\MapLayerPref;
use App\Models\MapWaypoint;
use App\Models\Mission;
use App\Models\Report;
use App\Models\RouteMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * De /maps-workspace: één kaart met alle kaarten van de gebruiker als layer.
 *
 * Twee-traps laden. `index()` levert alléén metadata (titel, rol, counts,
 * bbox, voorkeuren) — een paar kB, ook bij dertig kaarten. De features van een
 * layer komen pas met `layer()`, lui opgehaald zodra de layer zichtbaar wordt
 * of in beeld komt. Eén mega-payload zou bij 30×200 waypoints megabytes zijn
 * en de hoofdthread laten haperen; 30×4 losse calls zijn 120 requests.
 *
 * Alle aggregaties gaan per resource in één GROUP BY-query — nooit per kaart
 * in een loop, want dat is precies de N+1 die bij dertig layers pijn doet.
 */
class WorkspaceController extends Controller
{
    use AuthorizesMapAccess;

    /**
     * GET /api/v1/workspace
     * Metadata van elke kaart waar de gebruiker bij kan, zonder features.
     */
    public function index()
    {
        $userId = Auth::id();

        $access = app(\App\Services\MapAccess::class);
        $maps = $access->accessibleQuery(Auth::user())
            ->where('status', '!=', 'archived')
            ->get();

        $roles = $access->rolesFor(Auth::user(), $maps);
        $ids = $maps->pluck('id')->all();
        if (empty($ids)) {
            return response()->json(['layers' => []]);
        }

        // ── Aggregaties: vier queries, ongeacht het aantal kaarten ──────────
        $wpStats = MapWaypoint::whereIn('map_id', $ids)
            ->groupBy('map_id')
            ->select('map_id', DB::raw('COUNT(*) as n'),
                DB::raw('MIN(lon) as min_lon'), DB::raw('MAX(lon) as max_lon'),
                DB::raw('MIN(lat) as min_lat'), DB::raw('MAX(lat) as max_lat'))
            ->get()->keyBy('map_id');

        $reportStats = Report::whereIn('map_id', $ids)
            ->groupBy('map_id')
            ->select('map_id', DB::raw('COUNT(*) as n'),
                DB::raw('MIN(longitude) as min_lon'), DB::raw('MAX(longitude) as max_lon'),
                DB::raw('MIN(latitude) as min_lat'), DB::raw('MAX(latitude) as max_lat'))
            ->get()->keyBy('map_id');

        $rmStats = RouteMap::whereIn('map_id', $ids)
            ->groupBy('map_id')
            ->select('map_id', DB::raw('COUNT(*) as n'))
            ->get()->keyBy('map_id');

        $prefs = MapLayerPref::where('user_id', $userId)
            ->whereIn('map_id', $ids)
            ->get()->keyBy('map_id');

        // Kaarten met minstens één geaccepteerde collaborator: hierop — en
        // alleen hierop — abonneert de client zich op een Reverb-kanaal.
        $collaborative = DB::table('map_collaborators')
            ->whereIn('map_id', $ids)
            ->where('status', 'accepted')
            ->distinct()->pluck('map_id')->flip();

        // Kaart → missie, zodat de frontend de missie-filter kan opbouwen
        // zonder alle missies apart op te halen (_resolveLinkedMission deed dat
        // eerder per kaart).
        $missionByMap = [];
        Mission::where('owner_id', $userId)
            ->whereNotNull('map')
            ->get(['id', 'name', 'map', 'area', 'status'])
            ->each(function ($m) use (&$missionByMap) {
                $mapId = $m->map['id'] ?? null;
                if ($mapId && ($m->map['source'] ?? 'server') === 'server') {
                    $missionByMap[$mapId] = [
                        'id' => $m->id, 'name' => $m->name,
                        'status' => $m->status, 'area' => $m->area,
                    ];
                }
            });

        $order = 0;
        $layers = $maps->map(function (Map $map) use (
            $userId, $wpStats, $reportStats, $rmStats, $prefs, $missionByMap,
            $collaborative, $roles, &$order
        ) {
            $isOwner  = (int) $map->owner_id === $userId;
            $role     = $roles[(string) $map->id] ?? null;
            $pref     = $prefs->get($map->id);
            $wp       = $wpStats->get($map->id);
            $rep      = $reportStats->get($map->id);

            return [
                'id'            => $map->id,
                'title'         => $map->title,
                'kind'          => 'server',
                'my_role'       => $role,
                'is_owner'      => $isOwner,
                'can_edit'      => in_array($role, ['owner', 'editor'], true),
                'collaborative' => $collaborative->has($map->id),
                'mission'       => $missionByMap[$map->id] ?? null,
                'bbox'          => $this->mergeBbox($wp, $rep),
                'counts'        => [
                    'waypoints' => (int) ($wp->n ?? 0),
                    'reports'   => (int) ($rep->n ?? 0),
                    'routemaps' => (int) ($rmStats->get($map->id)->n ?? 0),
                ],
                'prefs' => [
                    'visible' => $pref ? (bool) $pref->visible : true,
                    'color'   => $pref->color ?? '#2b7fff',
                    'order'   => $pref ? (int) $pref->order : $order++,
                ],
            ];
        })->sortBy('prefs.order')->values();

        return response()->json(['layers' => $layers]);
    }

    /**
     * GET /api/v1/workspace/layers/{mapId}
     * Alle features van één layer in één response.
     *
     * Tracks zitten hier bewust niet bij: die hangen aan een missie
     * (mission_tracks), niet aan een kaart.
     */
    public function layer($mapId)
    {
        $map = Map::findOrFail($mapId);

        if (! $this->authorizeMapAccess($map)) {
            abort(403, 'Geen toegang tot deze kaart.');
        }

        return response()->json([
            'id'        => $map->id,
            // Zelfde vorm als GET /maps/{id}/waypoints, zodat de bestaande
            // client-parsers ongewijzigd blijven.
            'waypoints' => MapWaypoint::where('map_id', $mapId)->with('images')->get()
                               ->map(fn($w) => $w->toClientArray())->values(),
            // Zelfde vorm als GET /reports/maps/{mapId}.
            'reports'   => Report::where('map_id', $mapId)
                               ->orderBy('created_at', 'desc')->get(),
            // Zelfde vorm als GET /routemaps?map_id=.
            'routemaps' => RouteMap::where('map_id', $mapId)
                               ->orderBy('created_at', 'desc')->get(),
        ]);
    }

    /**
     * PUT /api/v1/workspace/layer-prefs
     * Body: { layers: [{ map_id, visible?, color?, order? }, …] }
     *
     * De client stuurt de hele geordende lijst in één keer (gedebounced), dus
     * dit is één upsert in plaats van N losse requests.
     */
    public function updatePrefs(Request $request)
    {
        $data = $request->validate([
            'layers'           => 'required|array|max:200',
            'layers.*.map_id'  => 'required|uuid',
            'layers.*.visible' => 'sometimes|boolean',
            'layers.*.color'   => 'sometimes|string|max:20',
            'layers.*.order'   => 'sometimes|integer|min:0',
        ]);

        $userId = Auth::id();

        // Alleen kaarten waar deze gebruiker daadwerkelijk bij kan — anders zou
        // je voorkeuren kunnen schrijven op willekeurige kaart-id's.
        $allowed = app(\App\Services\MapAccess::class)->accessibleQuery(Auth::user())
            ->whereIn('maps.id', collect($data['layers'])->pluck('map_id'))
            ->pluck('maps.id')->flip();

        $now  = now();
        $rows = [];
        foreach ($data['layers'] as $l) {
            if (! $allowed->has($l['map_id'])) continue;
            $rows[] = [
                'id'         => (string) Str::uuid(),
                'user_id'    => $userId,
                'map_id'     => $l['map_id'],
                'visible'    => $l['visible'] ?? true,
                'color'      => $l['color'] ?? '#2b7fff',
                'order'      => $l['order'] ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows) {
            MapLayerPref::upsert(
                $rows,
                ['user_id', 'map_id'],
                ['visible', 'color', 'order', 'updated_at']
            );
        }

        return response()->json(['saved' => count($rows)]);
    }

    /**
     * Bounding box over waypoints + gemarkeerde gebieden. Null als de layer
     * nog leeg is — de client slaat 'm dan over bij het viewport-laden.
     */
    private function mergeBbox($wp, $rep): ?array
    {
        $lons = [];
        $lats = [];
        foreach ([$wp, $rep] as $s) {
            if (! $s || $s->min_lon === null) continue;
            $lons[] = (float) $s->min_lon;
            $lons[] = (float) $s->max_lon;
            $lats[] = (float) $s->min_lat;
            $lats[] = (float) $s->max_lat;
        }
        if (! $lons) return null;

        // Afronden op 7 decimalen (≈1 cm): de decimal-kolommen komen als
        // strings binnen en leveren anders 50-cijferige floats in de JSON.
        return [
            round(min($lons), 7), round(min($lats), 7),
            round(max($lons), 7), round(max($lats), 7),
        ];
    }
}
