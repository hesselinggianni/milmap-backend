<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\RouteAssignment;
use App\Models\RouteAssignmentCollaborator;
use App\Models\RouteAssignmentLog;
use App\Models\RouteMap;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * KAKO-trainingen (kaart-en-kompasopdrachten).
 *
 * De routes worden in de app gegenereerd (preview + opnieuw genereren zonder
 * serverround-trip) en hier in één transactie opgeslagen: de opdracht én de
 * routekaarten die erbij horen. Opnieuw genereren vervangt de routekaarten
 * van de opdracht; losse routekaarten op dezelfde kaart blijven onaangeroerd.
 *
 * Toegang: eigenaar, uitgenodigde gebruiker (editor/viewer, na accepteren) of
 * iemand met toegang tot de kaart (MapPolicy). Elke wijziging komt in het log.
 */
class RouteAssignmentController extends Controller
{
    private const MAX_ROUTES = 200;
    private const MAX_PARTICIPANTS = 500;
    private const MAX_SETTINGS_BYTES = 400000;

    /** Rol van de ingelogde gebruiker: owner | editor | viewer | invited | null. */
    private function roleFor(RouteAssignment $a): ?string
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }
        if ((int) $a->owner_id === (int) $user->id) {
            return 'owner';
        }
        $collab = $a->collaborators()->where('user_id', $user->id)->first();
        if ($collab) {
            return $collab->status === 'accepted' ? $collab->role : 'invited';
        }
        $map = Map::find($a->map_id);
        if ($map) {
            if (Gate::allows('update', $map)) {
                return 'editor';
            }
            if (Gate::allows('view', $map)) {
                return 'viewer';
            }
        }
        return null;
    }

    private function load(string $id, string $need = 'view'): array
    {
        $a = RouteAssignment::findOrFail($id);
        $role = $this->roleFor($a);
        $ok = match ($need) {
            'owner' => $role === 'owner',
            'edit' => in_array($role, ['owner', 'editor'], true),
            default => in_array($role, ['owner', 'editor', 'viewer'], true),
        };
        abort_unless($ok, 403, 'Geen toegang tot deze KAKO-training');
        return [$a, $role];
    }

    private function authorizeMap(string $mapId, bool $requireEdit = false): Map
    {
        $map = Map::findOrFail($mapId);
        $this->authorize($requireEdit ? 'update' : 'view', $map);
        return $map;
    }

    private function log(RouteAssignment $a, string $action, string $summary, array $changes = []): void
    {
        $user = auth()->user();
        RouteAssignmentLog::create([
            'route_assignment_id' => $a->id,
            'user_id' => $user?->id,
            'user_name' => $user ? $user->full_name : 'Systeem',
            'action' => $action,
            'summary' => mb_substr($summary, 0, 500),
            'changes' => $changes ?: null,
            'created_at' => now(),
        ]);
    }

    /** GET /route-assignments — overzicht van alle KAKO-trainingen van deze gebruiker. */
    public function overview()
    {
        $user = auth()->user();
        $mapIds = app(\App\Services\MapAccess::class)->accessibleQuery($user)->pluck('id');
        $invited = RouteAssignmentCollaborator::where('user_id', $user->id)->pluck('route_assignment_id');

        $items = RouteAssignment::query()
            ->where(fn ($q) => $q->where('owner_id', $user->id)->orWhereIn('id', $invited)->orWhereIn('map_id', $mapIds))
            ->with(['owner:id,first_name,last_name,email', 'collaborators.user:id,first_name,last_name,email'])
            ->withCount('routeMaps')
            ->latest('updated_at')
            ->get();

        $folderOf = \App\Models\KakoFolderItem::where('user_id', $user->id)->pluck('folder_id', 'route_assignment_id');
        $maps = Map::withoutGlobalScopes()->whereIn('id', $items->pluck('map_id')->unique())->pluck('title', 'id');
        $lastLog = RouteAssignmentLog::whereIn('route_assignment_id', $items->pluck('id'))
            ->orderBy('id')->get()->groupBy('route_assignment_id')->map->last();

        $out = $items->map(function (RouteAssignment $a) use ($maps, $lastLog, $folderOf) {
            $role = $this->roleFor($a);
            if (! $role) {
                return null;
            }
            $log = $lastLog->get($a->id);
            // Kaartuitsnede voor de kaart in het overzicht: omlijning van de posten + startpunt.
            $posts = collect($a->settings['posts'] ?? [])->filter(fn ($p) => isset($p['lat'], $p['lon']))->values();
            $start = $posts->firstWhere('id', (string) ($a->settings['startId'] ?? '')) ?? $posts->first();
            return [
                'id' => $a->id,
                'title' => $a->title,
                'type' => $a->type,
                'map_id' => $a->map_id,
                'map_title' => $maps[$a->map_id] ?? null,
                'owner_name' => $a->owner?->full_name,
                'role' => $role,
                'status' => $a->settings['status'] ?? 'draft',
                'folder_id' => $folderOf[$a->id] ?? null,
                // Wie heeft toegang: eigenaar + uitgenodigden (bewerker = bewerken, kijker = alleen gebruiken).
                'access' => collect([['user_id' => $a->owner_id, 'name' => $a->owner?->full_name, 'role' => 'owner', 'status' => 'accepted']])
                    ->merge($a->collaborators->map(fn ($c) => ['user_id' => $c->user_id, 'name' => $c->user?->full_name, 'role' => $c->role, 'status' => $c->status]))
                    ->values(),
                'posts_count' => max(0, $posts->count() - 1),
                'start' => $start ? ['lat' => (float) $start['lat'], 'lon' => (float) $start['lon']] : null,
                'bbox' => $posts->isEmpty() ? null : [
                    'min_lat' => (float) $posts->min('lat'), 'max_lat' => (float) $posts->max('lat'),
                    'min_lon' => (float) $posts->min('lon'), 'max_lon' => (float) $posts->max('lon'),
                ],
                'participants_count' => count($a->participants ?? []),
                'routes_count' => $a->route_maps_count,
                'created_at' => $a->created_at,
                'updated_at' => $a->updated_at,
                'updated_by' => $log?->user_name,
                'updated_summary' => $log?->summary,
            ];
        })->filter()->values();

        return response()->json($out);
    }

    /** GET /maps/{mapId}/route-assignments */
    public function index(string $mapId)
    {
        $map = Map::findOrFail($mapId);
        $query = RouteAssignment::where('map_id', $mapId);
        if (! Gate::allows('view', $map)) {
            // Geen toegang tot de kaart zelf: alleen de trainingen waarvoor je bent uitgenodigd (en hebt geaccepteerd).
            $ids = RouteAssignmentCollaborator::where('user_id', auth()->id())->where('status', 'accepted')->pluck('route_assignment_id');
            abort_if($ids->isEmpty() || ! (clone $query)->whereIn('id', $ids)->exists(), 403, 'Geen toegang tot deze kaart');
            $query->whereIn('id', $ids);
        }

        $assignments = $query
            ->with(['routeMaps:id,route_assignment_id,route_code'])
            ->latest()
            ->get()
            ->map(fn (RouteAssignment $a) => $this->present($a, withRoutes: false));

        return response()->json($assignments);
    }

    /** GET /route-assignments/{id} */
    public function show(string $id)
    {
        [$assignment, $role] = $this->load($id);

        return response()->json($this->present($assignment->load(['routeMaps', 'collaborators.user']), role: $role));
    }

    /** POST /maps/{mapId}/route-assignments */
    public function store(Request $request, string $mapId)
    {
        $this->authorizeMap($mapId, requireEdit: true);
        $validated = $this->validatePayload($request, creating: true);

        $assignment = DB::transaction(function () use ($validated, $mapId) {
            $assignment = RouteAssignment::create([
                'id' => (string) Str::uuid(),
                'map_id' => $mapId,
                'owner_id' => auth()->id(),
                'title' => $validated['title'],
                'type' => $validated['type'] ?? 'training',
                'settings' => $validated['settings'] ?? [],
                'participants' => $validated['participants'] ?? [],
            ]);

            $this->createRoutes($assignment, $validated['routes'] ?? []);

            $n = count($validated['participants'] ?? []);
            $r = count($validated['routes'] ?? []);
            $this->log($assignment, 'created', "KAKO-training aangemaakt ({$n} deelnemers, {$r} routes)", [
                'Naam: ' . $assignment->title,
                'Type: ' . ($assignment->type === 'exam' ? 'Examen' : 'Training'),
            ]);

            return $assignment;
        });

        return response()->json($this->present($assignment->load(['routeMaps', 'collaborators.user']), role: 'owner'), 201);
    }

    /**
     * PUT /route-assignments/{id}
     * Zonder `routes` blijven de routekaarten staan (alleen instellingen,
     * deelnemers of planning gewijzigd). Mét `routes` worden de routekaarten
     * van deze opdracht vervangen (opnieuw genereren).
     * `expected_version` (unix-tijd van updated_at zoals de client die kent)
     * voorkomt dat twee bewerkers elkaars werk stil overschrijven → 409.
     */
    public function update(Request $request, string $id)
    {
        [$assignment, $role] = $this->load($id, 'edit');
        $validated = $this->validatePayload($request, creating: false);

        if ($request->filled('expected_version') && (int) $request->input('expected_version') !== $assignment->updated_at->timestamp) {
            $last = $assignment->logs()->latest('id')->first();
            return response()->json([
                'message' => 'Iemand anders heeft deze training gewijzigd' . ($last ? " ({$last->user_name}: {$last->summary})" : '') . '. Laad de nieuwste versie en probeer opnieuw.',
                'conflict' => true,
            ], 409);
        }

        // Bewerk- of productiemodus: in productie staan instellingen, posten, routes en deelnemers
        // vast; alleen de beoordeling (resultaten) mag nog veranderen. Wisselen van modus: alleen de eigenaar.
        $oldStatus = $assignment->settings['status'] ?? 'draft';
        $newStatus = array_key_exists('settings', $validated) ? ($validated['settings']['status'] ?? $oldStatus) : $oldStatus;
        if ($newStatus !== $oldStatus && $role !== 'owner') {
            return response()->json(['message' => 'Alleen de eigenaar kan de training in productie zetten of terugzetten naar bewerken.'], 403);
        }
        if ($oldStatus === 'live' && $newStatus === 'live' && ($locked = $this->lockedChange($assignment, $validated))) {
            return response()->json(['message' => 'Deze training staat in productie: wijzigen van ' . $locked . ' kan niet. Zet de training eerst terug naar bewerken.', 'locked' => true], 423);
        }

        DB::transaction(function () use ($assignment, $validated, $oldStatus, $newStatus) {
            $changes = $this->diff($assignment, $validated);
            if ($newStatus !== $oldStatus) {
                array_unshift($changes, $newStatus === 'live' ? 'Training in productie gezet (instellingen vergrendeld)' : 'Training terug in bewerkmodus');
            }

            $assignment->update(collect($validated)->only(['title', 'type', 'settings', 'participants'])->all());

            if (array_key_exists('routes', $validated)) {
                $this->detachRoutes($assignment, delete: true);
                $this->createRoutes($assignment, $validated['routes'] ?? []);
                $kinds = collect($validated['routes'])->countBy(fn ($r) => $r['kind'] ?? 'course');
                $changes[] = sprintf('Routes opnieuw gegenereerd: %d koers + %d paden', $kinds['course'] ?? 0, $kinds['paths'] ?? 0);
            }

            if ($changes) {
                $summary = implode('; ', array_slice($changes, 0, 3)) . (count($changes) > 3 ? sprintf(' (+%d meer)', count($changes) - 3) : '');
                $this->log($assignment, 'updated', $summary, $changes);
            }
        });

        return response()->json($this->present($assignment->fresh()->load(['routeMaps', 'collaborators.user']), role: $role));
    }

    /**
     * DELETE /route-assignments/{id}?with_routes=1
     * Standaard blijven de routekaarten bestaan als losse routekaarten.
     */
    public function destroy(Request $request, string $id)
    {
        [$assignment] = $this->load($id, 'owner');
        if (($assignment->settings['status'] ?? 'draft') === 'live') {
            return response()->json(['message' => 'Een training in productie kan niet worden verwijderd. Zet hem eerst terug naar bewerken.'], 423);
        }

        DB::transaction(function () use ($assignment, $request) {
            $this->detachRoutes($assignment, delete: $request->boolean('with_routes'));
            $assignment->delete();
        });

        return response()->json(['message' => 'Opdracht verwijderd']);
    }

    // ── Delen ──────────────────────────────────────────────────────────

    /** POST /route-assignments/{id}/collaborators { email, role } */
    public function invite(Request $request, string $id)
    {
        [$assignment] = $this->load($id, 'owner');
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'in:editor,viewer'],
        ]);

        $user = User::where('email', mb_strtolower(trim($data['email'])))->first();
        if (! $user) {
            return response()->json(['message' => 'Er is geen MilMap-gebruiker met dit e-mailadres.'], 404);
        }
        if ((int) $user->id === (int) $assignment->owner_id) {
            return response()->json(['message' => 'Dit is de eigenaar van de training.'], 422);
        }

        $collab = RouteAssignmentCollaborator::updateOrCreate(
            ['route_assignment_id' => $assignment->id, 'user_id' => $user->id],
            ['role' => $data['role'], 'status' => 'pending', 'invited_by' => auth()->id()]
        );
        $this->log($assignment, 'invited', sprintf('%s uitgenodigd als %s', $user->full_name, $data['role'] === 'editor' ? 'bewerker' : 'kijker'));

        return response()->json($this->presentCollaborator($collab->load('user')), 201);
    }

    /** PATCH /route-assignments/{id}/collaborators/{userId} { role } */
    public function changeRole(Request $request, string $id, int $userId)
    {
        [$assignment] = $this->load($id, 'owner');
        $data = $request->validate(['role' => ['required', 'in:editor,viewer']]);
        $collab = RouteAssignmentCollaborator::where('route_assignment_id', $assignment->id)->where('user_id', $userId)->firstOrFail();
        $old = $collab->role;
        $collab->update(['role' => $data['role']]);
        if ($old !== $data['role']) {
            $this->log($assignment, 'role_changed', sprintf('Rechten van %s gewijzigd: %s → %s', $collab->user->full_name, $old, $data['role']));
        }
        return response()->json($this->presentCollaborator($collab->load('user')));
    }

    /** DELETE /route-assignments/{id}/collaborators/{userId} — eigenaar verwijdert, of je verlaat de training zelf. */
    public function removeCollaborator(string $id, int $userId)
    {
        $assignment = RouteAssignment::findOrFail($id);
        $isOwner = (int) $assignment->owner_id === (int) auth()->id();
        abort_unless($isOwner || $userId === (int) auth()->id(), 403);

        $collab = RouteAssignmentCollaborator::where('route_assignment_id', $assignment->id)->where('user_id', $userId)->firstOrFail();
        $name = $collab->user->full_name;
        $collab->delete();
        $this->log($assignment, $isOwner ? 'removed' : 'left', $isOwner ? "{$name} verwijderd uit de training" : "{$name} heeft de training verlaten");

        return response()->json(['message' => 'Verwijderd']);
    }

    /** POST /route-assignments/{id}/invitation/{accept|decline} */
    public function respond(string $id, string $answer)
    {
        abort_unless(in_array($answer, ['accept', 'decline'], true), 404);
        $assignment = RouteAssignment::findOrFail($id);
        $collab = RouteAssignmentCollaborator::where('route_assignment_id', $assignment->id)
            ->where('user_id', auth()->id())->where('status', 'pending')->firstOrFail();

        if ($answer === 'accept') {
            $collab->update(['status' => 'accepted']);
            $this->log($assignment, 'accepted', 'Uitnodiging geaccepteerd');
        } else {
            $collab->delete();
            $this->log($assignment, 'declined', 'Uitnodiging geweigerd');
        }
        return response()->json(['status' => $answer === 'accept' ? 'accepted' : 'declined']);
    }

    /** GET /route-assignments/{id}/log */
    public function logIndex(string $id)
    {
        [$assignment] = $this->load($id);
        return response()->json(
            $assignment->logs()->orderByDesc('id')->limit(500)->get(['id', 'user_id', 'user_name', 'action', 'summary', 'changes', 'created_at'])
        );
    }

    // ── Log: wat is er precies veranderd? ─────────────────────────────

    /** Beschrijving van de eerste vergrendelde wijziging, of null als alleen resultaten veranderen. */
    private function lockedChange(RouteAssignment $a, array $new): ?string
    {
        if (array_key_exists('routes', $new)) {
            return 'routes opnieuw genereren';
        }
        if ((isset($new['title']) && $new['title'] !== $a->title) || (isset($new['type']) && $new['type'] !== $a->type)) {
            return 'naam en type';
        }
        if (array_key_exists('settings', $new)) {
            $strip = fn (array $x) => array_diff_key($x, array_flip(['seed', 'view', 'showPrikkerOnMap', 'posts', 'print']));
            if ($strip($new['settings'] ?? []) != $strip($a->settings ?? [])) {
                return 'instellingen, tempo, planning, puntentelling en posten';
            }
        }
        if (array_key_exists('participants', $new)) {
            $noResult = fn (array $list) => array_map(fn ($p) => array_diff_key($p, ['result' => 1]), array_values($list));
            if ($noResult($new['participants'] ?? []) != $noResult($a->participants ?? [])) {
                return 'deelnemers en routekoppeling';
            }
        }
        return null;
    }

    private const SETTING_LABELS = [
        'pace' => 'Tempo per km', 'plan' => 'Planning', 'scoring' => 'Puntentelling',
        'restrictions' => 'App-beperkingen', 'course' => 'Koersroutes', 'paths' => 'Padenroutes',
        'offset' => 'Letter-offset', 'orderMode' => 'Volgorde koers/paden', 'longestFirst' => 'Langste route eerst',
        'mode' => 'Uitvoering (papier/app)', 'print' => 'A4-print', 'emergency' => 'Noodprocedure', 'startId' => 'Start/finish-waypoint',
    ];
    private const IGNORED_SETTINGS = ['seed', 'view', 'showPrikkerOnMap', 'posts', 'status'];

    private function diff(RouteAssignment $a, array $new): array
    {
        $out = [];
        if (isset($new['title']) && $new['title'] !== $a->title) {
            $out[] = sprintf('Naam gewijzigd: "%s" → "%s"', $a->title, $new['title']);
        }
        if (isset($new['type']) && $new['type'] !== $a->type) {
            $out[] = sprintf('Type gewijzigd: %s → %s', $a->type, $new['type']);
        }
        if (array_key_exists('settings', $new)) {
            $old = $a->settings ?? [];
            $nw = $new['settings'] ?? [];
            if (($old['poolIds'] ?? []) != ($nw['poolIds'] ?? [])) {
                $out[] = sprintf('Pool van posten: %d → %d posten', count($old['poolIds'] ?? []), count($nw['poolIds'] ?? []));
            }
            if (($old['prikkers'] ?? []) != ($nw['prikkers'] ?? [])) {
                $changed = 0;
                foreach (array_unique(array_merge(array_keys($old['prikkers'] ?? []), array_keys($nw['prikkers'] ?? []))) as $k) {
                    if (($old['prikkers'][$k] ?? null) != ($nw['prikkers'][$k] ?? null)) {
                        $changed++;
                    }
                }
                $out[] = sprintf('Prikkerpatronen gewijzigd bij %d post(en)', $changed);
            }
            if (($old['descriptions'] ?? []) != ($nw['descriptions'] ?? [])) {
                $changed = [];
                foreach (array_unique(array_merge(array_keys($old['descriptions'] ?? []), array_keys($nw['descriptions'] ?? []))) as $k) {
                    if (($old['descriptions'][$k] ?? '') !== ($nw['descriptions'][$k] ?? '')) {
                        $label = collect($nw['posts'] ?? $old['posts'] ?? [])->firstWhere('id', (string) $k)['label'] ?? $k;
                        $changed[] = sprintf('Omschrijving post %s: %s → %s', $label, $this->display($old['descriptions'][$k] ?? null), $this->display($nw['descriptions'][$k] ?? null));
                    }
                }
                $out = array_merge($out, $changed);
            }
            foreach (array_unique(array_merge(array_keys($old), array_keys($nw))) as $key) {
                if (in_array($key, self::IGNORED_SETTINGS, true) || in_array($key, ['poolIds', 'prikkers', 'descriptions'], true)) {
                    continue;
                }
                if (($old[$key] ?? null) == ($nw[$key] ?? null)) {
                    continue;
                }
                $label = self::SETTING_LABELS[$key] ?? $key;
                foreach ($this->flatDiff($old[$key] ?? null, $nw[$key] ?? null) as $line) {
                    $out[] = $label . ($line['path'] ? ' › ' . $line['path'] : '') . ': ' . $line['from'] . ' → ' . $line['to'];
                }
            }
        }
        if (array_key_exists('participants', $new)) {
            $out = array_merge($out, $this->diffParticipants($a->participants ?? [], $new['participants'] ?? []));
        }
        return array_slice($out, 0, 60);
    }

    private function flatDiff($old, $new, string $path = ''): array
    {
        // Nieuwe instelling die voor het eerst wordt opgeslagen (oude trainingen kenden 'mixed' nog niet): geen wijziging.
        if ($old === null && str_ends_with($path, 'mixed')) {
            return [];
        }
        if (is_array($old) && is_array($new) && ! array_is_list($old) && ! array_is_list($new)) {
            $lines = [];
            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
                $lines = array_merge($lines, $this->flatDiff($old[$k] ?? null, $new[$k] ?? null, ltrim($path . ' › ' . $k, ' ›')));
            }
            return $lines;
        }
        if ($old == $new) {
            return [];
        }
        return [['path' => $path, 'from' => $this->display($old), 'to' => $this->display($new)]];
    }

    private function display($v): string
    {
        if ($v === null || $v === '') return '—';
        if (is_bool($v)) return $v ? 'aan' : 'uit';
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
        return mb_strimwidth((string) $v, 0, 90, '…');
    }

    private function diffParticipants(array $old, array $new): array
    {
        $out = [];
        $byId = fn (array $list) => collect($list)->keyBy(fn ($p, $i) => $p['id'] ?? "i{$i}");
        $o = $byId($old);
        $n = $byId($new);
        foreach ($n as $id => $p) {
            if (! $o->has($id)) {
                $out[] = sprintf('Deelnemer toegevoegd: %s', $p['name'] ?? '?');
                continue;
            }
            $q = $o[$id];
            $name = $p['name'] ?? '?';
            if (($q['name'] ?? null) !== ($p['name'] ?? null)) {
                $out[] = sprintf('Deelnemer hernoemd: %s → %s', $q['name'] ?? '?', $name);
            }
            if (($q['route_code'] ?? null) !== ($p['route_code'] ?? null)) {
                $out[] = sprintf('%s: route %s → %s', $name, $q['route_code'] ?? '—', $p['route_code'] ?? '—');
            }
            if (($q['order'] ?? null) !== ($p['order'] ?? null)) {
                $out[] = sprintf('%s: volgorde gewijzigd', $name);
            }
            if (($q['result'] ?? null) != ($p['result'] ?? null)) {
                $qr = $q['result'] ?? [];
                $pr = $p['result'] ?? [];
                $bits = [];
                if (($qr['finish_time'] ?? null) !== ($pr['finish_time'] ?? null)) {
                    $bits[] = 'binnenkomsttijd ' . $this->display($qr['finish_time'] ?? null) . ' → ' . $this->display($pr['finish_time'] ?? null);
                }
                if (($qr['posts'] ?? []) != ($pr['posts'] ?? [])) {
                    $bits[] = 'geprikte posten bijgewerkt';
                }
                $out[] = sprintf('%s: beoordeling — %s', $name, $bits ? implode(', ', $bits) : 'gewijzigd');
            }
        }
        foreach ($o as $id => $q) {
            if (! $n->has($id)) {
                $out[] = sprintf('Deelnemer verwijderd: %s', $q['name'] ?? '?');
            }
        }
        return $out;
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        abort_if(strlen((string) json_encode($request->input('settings'))) > self::MAX_SETTINGS_BYTES, 422, 'Instellingen zijn te groot');

        return $request->validate([
            'title' => [$req, 'string', 'max:120'],
            'type' => ['sometimes', 'in:training,exam'],
            'expected_version' => ['sometimes', 'nullable', 'integer'],
            'settings' => ['sometimes', 'nullable', 'array'],

            'participants' => ['sometimes', 'nullable', 'array', 'max:' . self::MAX_PARTICIPANTS],
            'participants.*.id' => ['nullable', 'string', 'max:64'],
            'participants.*.name' => ['required', 'string', 'max:120'],
            'participants.*.route_code' => ['nullable', 'string', 'max:16'],
            // course_first = 1A (koers, dan paden), paths_first = A1.
            'participants.*.order' => ['nullable', 'in:course_first,paths_first'],
            // Beoordeling: { posts: {postId: 0..1}, finish_time: "HH:MM", note }
            'participants.*.result' => ['nullable', 'array'],

            'routes' => ['sometimes', 'array', 'max:' . self::MAX_ROUTES],
            'routes.*.code' => ['required', 'string', 'max:16', 'distinct'],
            'routes.*.title' => ['nullable', 'string', 'max:190'],
            // course = koersroute (cijfer, hemelsbreed), paths = padenroute (letter).
            'routes.*.kind' => ['nullable', 'in:course,paths'],
            'routes.*.color' => ['nullable', 'string', 'max:32'],
            'routes.*.speed' => ['nullable', 'numeric', 'min:0.1', 'max:50'],
            'routes.*.date' => ['nullable', 'date'],
            'routes.*.time' => ['nullable', 'date_format:H:i'],
            'routes.*.declination' => ['nullable', 'numeric', 'between:-180,180'],
            'routes.*.total_distance' => ['nullable', 'numeric', 'min:0'],
            'routes.*.total_time' => ['nullable', 'numeric', 'min:0'],
            'routes.*.locations' => ['required', 'array', 'min:2', 'max:60'],
            'routes.*.locations.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'routes.*.locations.*.lon' => ['required', 'numeric', 'between:-180,180'],
            // validated() houdt alleen velden met een regel over; zonder deze
            // regels verdwijnen posten (checkpoint) en padbochten (via).
            'routes.*.locations.*.id' => ['nullable'],
            'routes.*.locations.*.no' => ['nullable', 'integer'],
            'routes.*.locations.*.title' => ['nullable', 'string', 'max:190'],
            'routes.*.locations.*.mgrs' => ['nullable', 'string', 'max:32'],
            'routes.*.locations.*.checkpoint' => ['nullable', 'array'],
            'routes.*.locations.*.checkpoint.enabled' => ['nullable', 'boolean'],
            'routes.*.locations.*.checkpoint.label' => ['nullable', 'string', 'max:64'],
            'routes.*.locations.*.pause' => ['nullable', 'array'],
            'routes.*.locations.*.via' => ['nullable', 'array', 'max:400'],
            'routes.*.locations.*.via.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'routes.*.locations.*.via.*.lon' => ['required', 'numeric', 'between:-180,180'],
        ]);
    }

    /**
     * Koppelt de routekaarten los van de opdracht en verwijdert ze desgewenst
     * (soft delete → prullenbak). Eerst loskoppelen, zodat een routekaart die
     * later uit de prullenbak terugkomt een gewone losse routekaart is en niet
     * als dubbele "1a" in de opdracht opduikt.
     */
    private function detachRoutes(RouteAssignment $assignment, bool $delete): void
    {
        $routes = RouteMap::where('route_assignment_id', $assignment->id)->get();
        foreach ($routes as $route) {
            $route->forceFill(['route_assignment_id' => null, 'route_code' => null])->save();
            if ($delete) {
                $route->delete();
            }
        }
        // Al eerder weggegooide routekaarten van deze opdracht ook loskoppelen.
        RouteMap::onlyTrashed()
            ->where('route_assignment_id', $assignment->id)
            ->update(['route_assignment_id' => null, 'route_code' => null]);
    }

    private function createRoutes(RouteAssignment $assignment, array $routes): void
    {
        foreach ($routes as $route) {
            RouteMap::create([
                'id' => (string) Str::uuid(),
                'map_id' => $assignment->map_id,
                'owner_id' => auth()->id(),
                'route_assignment_id' => $assignment->id,
                'route_code' => $route['code'],
                'title' => $route['title'] ?? ($assignment->title . ' · ' . $route['code']),
                'color' => $route['color'] ?? null,
                'speed' => $route['speed'] ?? 5,
                'date' => $route['date'] ?? null,
                'time' => $route['time'] ?? null,
                'declination' => $route['declination'] ?? null,
                'meta' => ['kind' => $route['kind'] ?? 'course'],
                'locations' => array_values($route['locations']),
                'total_distance' => (int) round($route['total_distance'] ?? 0),
                'total_time' => (int) round($route['total_time'] ?? 0),
                'pause_time' => 0,
                'total_elevation' => 0,
            ]);
        }
    }

    private function presentCollaborator(RouteAssignmentCollaborator $c): array
    {
        return [
            'user_id' => $c->user_id,
            'name' => $c->user?->full_name,
            'email' => $c->user?->email,
            'role' => $c->role,
            'status' => $c->status,
        ];
    }

    private function present(RouteAssignment $assignment, bool $withRoutes = true, ?string $role = null): array
    {
        $data = $assignment->only(['id', 'map_id', 'owner_id', 'title', 'type', 'settings', 'participants', 'created_at', 'updated_at']);
        $data['settings'] = $data['settings'] ?: (object) [];
        $data['participants'] = $data['participants'] ?: [];
        $data['version'] = $assignment->updated_at?->timestamp;
        $data['route_map_ids'] = $assignment->routeMaps->pluck('id')->values();
        // De lijst-endpoint laadt bewust alleen id + code; de app heeft de
        // routekaarten van de kaart toch al.
        if ($withRoutes) {
            $data['route_maps'] = $assignment->routeMaps->values();
        }
        if ($role !== null) {
            $data['role'] = $role;
            $data['me'] = auth()->id();
            $data['owner_name'] = $assignment->owner?->full_name;
            $data['map_title'] = Map::withoutGlobalScopes()->where('id', $assignment->map_id)->value('title');
            $data['collaborators'] = $assignment->relationLoaded('collaborators')
                ? $assignment->collaborators->map(fn ($c) => $this->presentCollaborator($c))->values()
                : [];
        }
        return $data;
    }
}
