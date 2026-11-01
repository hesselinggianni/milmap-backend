<?php

namespace App\Http\Controllers;

use App\Events\MapWaypointChanged;
use App\Events\MapWaypointsReload;
use App\Http\Traits\AuthorizesMapAccess;
use App\Models\Map;
use App\Models\MapWaypoint;
use App\Models\MapWaypointImage;
use App\Models\MapWaypointRevision;
use App\Models\UserUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MapWaypointController extends Controller
{
    use AuthorizesMapAccess;
    use \App\Http\Traits\ResolvesArrivalTasks; // alleen arrivalTaskTitles() voor de wijzigingslog

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
     * Veel waypoints in één keer opslaan (bv. een GPX-import).
     * POST /api/v1/maps/{mapId}/waypoints/batch  { waypoints: [...] }
     * Zelfde velden en upsert op local_id als store(); max 500 per verzoek.
     * Eén broadcast-seintje i.p.v. één per punt.
     */
    public function storeBatch(Request $request, $mapId)
    {
        $map = Map::findOrFail($mapId);

        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }

        $data = $request->validate([
            'waypoints'            => 'required|array|min:1|max:500',
            'waypoints.*.local_id' => 'required|integer',
            'waypoints.*.lon'      => 'required|numeric|between:-180,180',
            'waypoints.*.lat'      => 'required|numeric|between:-90,90',
            'waypoints.*.mgrs'     => 'nullable|string|max:20',
            'waypoints.*.label'    => 'nullable|string|max:200',
            'waypoints.*.color'    => 'nullable|string|max:20',
            'waypoints.*.icon'     => 'nullable|string|max:30',
            'waypoints.*.type'     => 'nullable|string|max:30',
            'waypoints.*.note'     => 'nullable|string',
            'waypoints.*.props'    => 'nullable|array|max:50',
            'waypoints.*.props.*'  => 'nullable|string|max:500',
        ] + $this->taskRules('waypoints.*.'));

        $userId = Auth::id();
        $saved = DB::transaction(function () use ($data, $mapId, $userId) {
            $out = [];
            foreach ($data['waypoints'] as $w) {
                $saved = MapWaypoint::updateOrCreate(
                    ['map_id' => $mapId, 'local_id' => $w['local_id']],
                    [
                        'user_id' => $userId,
                        'lon'     => $w['lon'],
                        'lat'     => $w['lat'],
                        'mgrs'    => $w['mgrs'] ?? null,
                        'label'   => $w['label'] ?? null,
                        'color'   => $w['color'] ?? '#2b7fff',
                        'icon'    => $w['icon'] ?? 'pin',
                        'type'    => $w['type'] ?? null,
                        'note'    => $w['note'] ?? null,
                    ] + (array_key_exists('props', $w) ? ['props' => $w['props']] : [])
                      + $this->taskAttributes($w, fn () => MapWaypoint::where('map_id', $mapId)->where('local_id', $w['local_id'])->first())
                );
                if ($saved->wasRecentlyCreated) {
                    $this->logRevision($saved, 'created');
                }
                $out[] = $saved->toClientArray();
            }
            return $out;
        });

        broadcast(new MapWaypointsReload($mapId, count($saved), $userId))->toOthers();

        return response()->json(['waypoints' => $saved], 201);
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
            'props'    => 'nullable|array|max:50',
            'props.*'  => 'nullable|string|max:500',
        ] + $this->taskRules());

        $before = $this->snapshotBeforeSave($mapId, $data['local_id']);
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
            ] + (array_key_exists('props', $data) ? ['props' => $data['props']] : [])
              + $this->taskAttributes($data, fn () => MapWaypoint::where('map_id', $mapId)->where('local_id', $data['local_id'])->first())
        );
        $this->logWaypointSave($waypoint, $before);

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
            'props'   => 'nullable|array|max:50',
            'props.*' => 'nullable|string|max:500',
        ] + $this->taskRules());

        $before = $this->logSnapshot($waypoint);
        $waypoint->update(
            collect($data)->except(['tasks', 'task_radius'])->all()
            + $this->taskAttributes($data, fn () => $waypoint)
        );
        $this->logWaypointSave($waypoint, $before);

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

    // ── Wijzigingslog ─────────────────────────────────────────────────────────

    /**
     * Wijzigingsgeschiedenis van een waypoint, nieuwste eerst. Elke deelnemer
     * van de kaart mag meekijken (zoals bij gemarkeerde gebieden). Waypoints van
     * vóór het logboek hebben geen 'created'-regel: die vullen we aan uit het
     * waypoint zelf, zodat 'Aangemaakt' altijd klopt.
     * GET /api/v1/maps/{mapId}/waypoints/{localId}/revisions
     */
    public function revisions($mapId, $localId)
    {
        $map = Map::findOrFail($mapId);
        if (! $this->authorizeMapAccess($map)) {
            abort(403, 'Geen toegang tot deze kaart.');
        }
        $waypoint = MapWaypoint::with('user')->where('map_id', $mapId)->where('local_id', $localId)->firstOrFail();

        $revisions = $waypoint->revisions()->with('user')->get()->map->toApiArray()->values()->all();
        if (! collect($revisions)->contains('action', 'created')) {
            $revisions[] = [
                'id'         => 'created',
                'action'     => 'created',
                'changes'    => null,
                'user'       => $waypoint->user ? ['id' => $waypoint->user->id, 'name' => $waypoint->user->full_name] : null,
                'created_at' => optional($waypoint->created_at)->toIso8601String(),
            ];
        }

        return response()->json([
            'created_at' => optional($waypoint->created_at)->toIso8601String(),
            'created_by' => $waypoint->user?->full_name,
            'revisions'  => $revisions,
        ]);
    }

    private function logRevision(MapWaypoint $waypoint, string $action, ?array $diff = null): void
    {
        MapWaypointRevision::create([
            'map_waypoint_id' => $waypoint->id,
            'user_id'         => Auth::id(),
            'action'          => $action,
            'diff'            => $diff ?: null,
        ]);
    }

    /** Toestand voor het opslaan, of null als het waypoint nog niet bestaat. */
    private function snapshotBeforeSave($mapId, $localId): ?array
    {
        $existing = MapWaypoint::where('map_id', $mapId)->where('local_id', $localId)->first();
        return $existing ? $this->logSnapshot($existing) : null;
    }

    /** Nieuw → 'created'; bestaand → 'updated' met de veranderde velden (niets veranderd = geen regel). */
    private function logWaypointSave(MapWaypoint $waypoint, ?array $before): void
    {
        if ($before === null) {
            $this->logRevision($waypoint, 'created');
            return;
        }
        $diff = $this->diffSnapshots($before, $this->logSnapshot($waypoint));
        if ($diff) {
            $this->logRevision($waypoint, 'updated', $diff);
        }
    }

    /** De velden die in de log tellen (mgrs en tijdstempels zijn afgeleid, afvinken heeft een eigen regel). */
    private function logSnapshot(MapWaypoint $w): array
    {
        return [
            'lon'         => (float) $w->lon,
            'lat'         => (float) $w->lat,
            'label'       => $w->label ?: null,
            'color'       => $w->color ?: null,
            'icon'        => $w->icon ?: null,
            'type'        => $w->type ?: null,
            'note'        => $w->note ?: null,
            'props'       => $w->props ?: [],
            'tasks'       => $this->arrivalTaskTitles($w->tasks),
            'task_radius' => $w->task_radius,
        ];
    }

    /**
     * Veld-voor-veld diff { veld: { from, to } }. De positie is één veld
     * ('position'); eigen velden komen als 'props.<naam>'. De DB bewaart 7
     * decimalen, dus een kleiner verschil is afrondruis en geen verplaatsing.
     */
    private function diffSnapshots(array $before, array $after): array
    {
        $cut = fn ($v) => is_string($v) ? Str::limit($v, 300) : $v;
        $changes = [];

        if (abs($before['lon'] - $after['lon']) > 1e-6 || abs($before['lat'] - $after['lat']) > 1e-6) {
            $changes['position'] = [
                'from' => ['lon' => $before['lon'], 'lat' => $before['lat']],
                'to'   => ['lon' => $after['lon'], 'lat' => $after['lat']],
            ];
        }
        foreach (['label', 'color', 'icon', 'type', 'note', 'tasks', 'task_radius'] as $key) {
            if (json_encode($before[$key]) !== json_encode($after[$key])) {
                $changes[$key] = ['from' => $cut($before[$key]), 'to' => $cut($after[$key])];
            }
        }
        foreach (array_unique([...array_keys($before['props']), ...array_keys($after['props'])]) as $key) {
            $from = $before['props'][$key] ?? null;
            $to   = $after['props'][$key] ?? null;
            if ($from !== $to) {
                $changes['props.' . $key] = ['from' => $cut($from), 'to' => $cut($to)];
            }
        }

        return $changes;
    }

    // ── Aankomsttaken ─────────────────────────────────────────────────────────

    private const MAX_TASKS = 30;

    /**
     * Valideer-regels voor de taken van een waypoint. `$prefix` is 'waypoints.*.'
     * bij de batch-endpoint. done_at/done_by/done_by_name komen bewust NIET uit
     * het verzoek: die zet de server (zie resolveTasks), anders kan een client
     * een ander als afvinker opgeven.
     */
    private function taskRules(string $prefix = ''): array
    {
        return [
            $prefix . 'tasks'            => 'nullable|array|max:' . self::MAX_TASKS,
            $prefix . 'tasks.*.id'       => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            // Leeg mag: lege titels worden in resolveTasks() gewoon weggelaten,
            // zodat een half ingetypte regel het opslaan niet blokkeert.
            $prefix . 'tasks.*.title'    => 'nullable|string|max:200',
            $prefix . 'tasks.*.required' => 'nullable|boolean',
            $prefix . 'tasks.*.done'     => 'nullable|boolean',
            $prefix . 'task_radius'      => 'nullable|integer|between:10,1000',
        ];
    }

    /**
     * Kolommen om weg te schrijven voor de taak-velden in `$input` — leeg als
     * het verzoek ze niet meestuurt, zodat oudere app-versies (die `tasks` niet
     * kennen) de taken van een waypoint niet per ongeluk wissen.
     *
     * @param callable():(?MapWaypoint) $existing  haalt het bestaande waypoint lui op
     */
    private function taskAttributes(array $input, callable $existing): array
    {
        $out = [];
        if (array_key_exists('tasks', $input)) {
            $out['tasks'] = $this->resolveTasks($existing()?->tasks, $input['tasks'] ?? []);
        }
        if (array_key_exists('task_radius', $input)) {
            $out['task_radius'] = $input['task_radius'];
        }
        return $out;
    }

    /**
     * Maak van de door de client gestuurde taken de opgeslagen lijst: alleen de
     * bekende velden, lege titels eruit, dubbele id's eruit, en afvink-gegevens
     * (wanneer/door wie) door de server gezet. Een taak die al afgevinkt was en
     * dat blijft, behoudt zijn oorspronkelijke tijdstip en afvinker.
     */
    private function resolveTasks(?array $existing, array $incoming): array
    {
        $before = collect($existing ?: [])->keyBy('id');
        $now    = now()->toIso8601String();
        $actor  = Auth::user();
        $name   = $actor ? mb_substr((string) ($actor->first_name ?: ($actor->name ?? '')), 0, 80) : '';

        $out  = [];
        $seen = [];
        foreach (array_slice($incoming, 0, self::MAX_TASKS) as $t) {
            $title = trim((string) ($t['title'] ?? ''));
            $id    = (string) ($t['id'] ?? '');
            if ($title === '' || $id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $prev    = $before->get($id);
            $done    = (bool) ($t['done'] ?? false);
            $wasDone = (bool) ($prev['done'] ?? false);
            $keep    = $done && $wasDone;

            $out[] = [
                'id'           => $id,
                'title'        => mb_substr($title, 0, 200),
                'required'     => (bool) ($t['required'] ?? false),
                'done'         => $done,
                'done_at'      => $done ? ($keep ? ($prev['done_at'] ?? $now) : $now) : null,
                'done_by'      => $done ? ($keep ? ($prev['done_by'] ?? null) : $actor?->id) : null,
                'done_by_name' => $done ? ($keep ? ($prev['done_by_name'] ?? null) : ($name !== '' ? $name : null)) : null,
            ];
        }
        return $out;
    }

    /**
     * Eén taak afvinken (of weer openzetten) zonder de rest van het waypoint
     * te overschrijven: twee mensen die tegelijk verschillende taken afvinken
     * wissen elkaar zo niet uit. Rij wordt vergrendeld voor lezen + schrijven.
     *
     * Toegang: iedereen met toegang tot de kaart, ook een 'viewer'. Afvinken is
     * het uitvoeren van het plan, niet het wijzigen ervan — een teamlid dat
     * alleen mag meekijken moet wél kunnen melden dat de taak gedaan is.
     * POST /api/v1/maps/{mapId}/waypoints/{localId}/tasks/{taskId}  { done: bool }
     */
    public function setTaskDone(Request $request, $mapId, $localId, string $taskId)
    {
        $map = Map::findOrFail($mapId);

        if (! $this->authorizeMapAccess($map)) {
            abort(403, 'Geen toegang tot deze kaart.');
        }

        $data = $request->validate([
            'done'     => 'required|boolean',
            // Positie van de afvinker (optioneel; zonder GPS-fix wordt alleen wie/wanneer gelogd).
            'lat'      => 'nullable|numeric|between:-90,90',
            'lon'      => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
        ]);

        $waypoint = DB::transaction(function () use ($mapId, $localId, $taskId, $data) {
            $waypoint = MapWaypoint::where('map_id', $mapId)
                ->where('local_id', $localId)
                ->lockForUpdate()
                ->firstOrFail();

            $tasks = $waypoint->tasks ?: [];
            $index = collect($tasks)->search(fn ($t) => ($t['id'] ?? null) === $taskId);
            abort_if($index === false, 404, 'Taak niet gevonden.');

            $tasks[$index]['done'] = (bool) $data['done'];
            $tasks = $this->resolveTasks($waypoint->tasks, $tasks);

            $waypoint->update(['tasks' => $tasks]);
            return $waypoint;
        });

        $this->logTaskEvent($request, $waypoint, $taskId, (bool) $data['done']);
        $this->logRevision($waypoint, $data['done'] ? 'task_done' : 'task_reopened', [
            'task' => ['title' => (string) (collect($waypoint->tasks ?: [])->firstWhere('id', $taskId)['title'] ?? '')],
        ]);

        broadcast(new MapWaypointChanged($mapId, $waypoint->toClientArray(), 'updated', Auth::id()))->toOthers();

        return response()->json(['waypoint' => $waypoint->toClientArray()]);
    }

    /** Eén regel in het logboek: wie vinkte wat af, wanneer, en vanaf waar. */
    private function logTaskEvent(Request $request, MapWaypoint $waypoint, string $taskId, bool $done): void
    {
        $user  = Auth::user();
        $task  = collect($waypoint->tasks ?: [])->firstWhere('id', $taskId);
        $lat   = $request->input('lat');
        $lon   = $request->input('lon');
        $dist  = null;
        if ($lat !== null && $lon !== null) {
            $r = 6371000;
            $p1 = deg2rad((float) $lat); $p2 = deg2rad((float) $waypoint->lat);
            $a = sin(($p2 - $p1) / 2) ** 2
               + cos($p1) * cos($p2) * sin(deg2rad((float) $waypoint->lon - (float) $lon) / 2) ** 2;
            $dist = (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
        }

        \Illuminate\Support\Facades\DB::table('map_waypoint_task_events')->insert([
            'map_id'          => $waypoint->map_id,
            'map_waypoint_id' => $waypoint->id,
            'task_id'         => $taskId,
            'task_title'      => mb_substr((string) ($task['title'] ?? ''), 0, 200),
            'user_id'         => $user?->id,
            'user_name'       => $user ? mb_substr((string) ($user->full_name ?? $user->name ?? ''), 0, 120) : null,
            'done'            => $done,
            'lat'             => $lat,
            'lon'             => $lon,
            'accuracy_m'      => $request->input('accuracy'),
            'distance_m'      => $dist,
            'ip'              => $request->ip(),
            'created_at'      => now(),
        ]);
    }

    /**
     * Logboek van een waypoint, nieuwste eerst. Alleen voor wie de kaart mag
     * bewerken: het bevat posities en IP-adressen van teamleden.
     * GET /api/v1/maps/{mapId}/waypoints/{localId}/tasks-log
     */
    public function taskLog($mapId, $localId)
    {
        $map = Map::findOrFail($mapId);
        if (! $this->authorizeMapAccess($map, editorRequired: true)) {
            abort(403, 'Geen bewerkrechten op deze kaart.');
        }
        $waypoint = MapWaypoint::where('map_id', $mapId)->where('local_id', $localId)->firstOrFail();

        return response()->json(['events' => DB::table('map_waypoint_task_events')
            ->where('map_waypoint_id', $waypoint->id)->orderByDesc('id')->limit(200)->get()]);
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
        $waypoint = MapWaypoint::where('map_id', $mapId)
            ->where('local_id', (int) $localId)
            ->first();
        if (! $waypoint) {
            try {
                $waypoint = MapWaypoint::create([
                    'map_id'   => $mapId,
                    'local_id' => (int) $localId,
                    'user_id'  => Auth::id(),
                    'lon'      => $request->input('lon', 0),
                    'lat'      => $request->input('lat', 0),
                    'mgrs'     => $request->input('mgrs'),
                    'label'    => $request->input('label'),
                    'color'    => $request->input('color', '#2b7fff'),
                    'icon'     => $request->input('icon', 'pin'),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Race: een gelijktijdig verzoek (bv. het opslaan van het waypoint
                // zelf, of een tweede foto) maakte dit waypoint net aan. Dan
                // gewoon dát waypoint gebruiken i.p.v. een 500 te geven.
                if (($e->errorInfo[0] ?? null) !== '23000') {
                    throw $e;
                }
                $waypoint = MapWaypoint::where('map_id', $mapId)
                    ->where('local_id', (int) $localId)
                    ->firstOrFail();
            }
        }

        if ($waypoint->images()->count() >= self::MAX_IMAGES) {
            abort(422, 'Maximaal ' . self::MAX_IMAGES . ' foto\'s per waypoint.');
        }

        $file = $request->file('image');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = Str::uuid() . '.' . $ext;
        try {
            $path = $file->storeAs("waypoints/{$waypoint->id}", $name, 'public');
        } catch (\Throwable $e) {
            report($e);
            abort(503, 'De foto kon niet worden opgeslagen. Probeer het opnieuw.');
        }
        // De public-disk geeft bij een schrijffout standaard false terug.
        // Maak dan geen fotorecord met een leeg pad.
        if (! $path) {
            \Illuminate\Support\Facades\Log::error('Waypoint-foto kon niet worden opgeslagen', [
                'map_id' => $mapId, 'waypoint_id' => $waypoint->id,
            ]);
            abort(503, 'De foto kon niet worden opgeslagen. Probeer het opnieuw.');
        }

        try {
            $image = $waypoint->images()->create([
                'user_id'       => Auth::id(),
                'path'          => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime'          => $file->getClientMimeType(),
                'size'          => $file->getSize(),
            ]);
        } catch (\Throwable $e) {
            // Geen verweesd bestand achterlaten als de database niet opslaat.
            try { Storage::disk('public')->delete($path); } catch (\Throwable $cleanupError) { report($cleanupError); }
            throw $e;
        }

        UserUpload::record(Auth::id(), $path, (int) $file->getSize(), $file->getClientMimeType(), 'waypoint');
        $this->logRevision($waypoint, 'image_added', ['image' => ['name' => mb_substr((string) $file->getClientOriginalName(), 0, 120)]]);

        $waypoint->load('images');
        try {
            broadcast(new MapWaypointChanged($mapId, $waypoint->toClientArray(), 'updated', Auth::id()))->toOthers();
        } catch (\Illuminate\Broadcasting\BroadcastException $e) {
            // De foto is opgeslagen. Een storing in live-updates mag geen 500
            // veroorzaken, anders uploadt de gebruiker dezelfde foto opnieuw.
            report($e);
        }

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
        $this->logRevision($waypoint, 'image_removed', ['image' => ['name' => mb_substr((string) $image->original_name, 0, 120)]]);
        $image->delete();

        $waypoint->load('images');
        broadcast(new MapWaypointChanged($mapId, $waypoint->toClientArray(), 'updated', Auth::id()))->toOthers();

        return response()->json(['waypoint' => $waypoint->toClientArray()]);
    }
}
