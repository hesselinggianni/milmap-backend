<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Models\TeamMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MissionController extends Controller
{
    /** Toegestane bijlagen bij een O-group-veld (geen svg/html/scripts). */
    private const OGROUP_FILE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic',
        'pdf', 'txt', 'csv',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'gpx', 'kml', 'kmz', 'geojson', 'zip',
    ];

    /** Video voor een presentatiepagina: eigen (hogere) limiet en alleen echte videotypes. */
    private const OGROUP_VIDEO_EXTENSIONS = ['mp4', 'm4v', 'webm', 'mov'];
    private const OGROUP_VIDEO_MIMES = ['video/mp4', 'video/x-m4v', 'video/webm', 'video/quicktime'];

    /**
     * Missiestatus: de ingebouwde statussen (planning, active, complete) of een eigen status. De definities van eigen
     * statussen (label, kleur) staan in de app; de missie bewaart alleen de sleutel, een korte slug zonder spaties.
     */
    private const STATUS_KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*$/';

    /**
     * List all missions the user can see: ones they own plus ones they were
     * invited to and accepted.
     * GET /api/v1/missions
     */
    public function index()
    {
        $userId = Auth::id();

        // Team IDs the user belongs to (for auto-viewer access)
        $teamIds = TeamMember::activeTeamIdsQuery($userId)->pluck('team_id');

        $missions = Mission::query()
            // Eager-load exactly what present()/roleFor() pull via loadMissing()
            // below, so the list isn't N+1 (one collaborators.user + one owner
            // query per mission otherwise — 'collaborators' alone doesn't cover
            // the nested .user relation that present() also needs).
            ->with([
                'collaborators.user:id,first_name,last_name,avatar_path',
                'owner:id,first_name,last_name,avatar_path',
                'linkedTeam' => fn ($q) => $q->withCount('members'),
            ])
            ->where('owner_id', $userId)
            ->orWhereHas('collaborators', function ($q) use ($userId) {
                $q->where('user_id', $userId)->where('status', 'accepted');
            })
            ->orWhere(function ($q) use ($teamIds) {
                // Auto-viewer: active missions whose linked team includes the user
                $q->where('status', 'active')
                  ->whereIn('linked_team_id', $teamIds);
            })
            ->latest('updated_at')
            ->get();

        return response()->json([
            // Logo data-URLs are omitted from the list to keep the payload lean;
            // they are only included when a single mission is opened.
            'data' => $missions->map(fn ($m) => $this->present($m, $userId, false)),
        ]);
    }

    /**
     * Single mission (owner or accepted collaborator).
     * GET /api/v1/missions/{id}
     */
    public function show($id)
    {
        $mission = Mission::findOrFail($id);
        $userId = Auth::id();

        if (!$mission->hasAccess($userId)) {
            abort(403, 'You do not have access to this mission');
        }

        return response()->json(['data' => $this->present($mission, $userId)]);
    }

    /**
     * Create a mission. The creator becomes the owner.
     * POST /api/v1/missions
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => ['nullable', 'string', 'max:64', 'regex:'.self::STATUS_KEY_PATTERN],
            'date' => 'nullable|date',
            'time' => 'nullable|string',
            'ogroup' => 'nullable|array',
            'map' => 'nullable|array',
            'map.id' => 'required_with:map|uuid',
            'map.source' => 'sometimes|in:server,local',
            'map.title' => 'sometimes|nullable|string|max:255',
            // Werkgebied (bounding box) waarbinnen de kaart blijft.
            'area' => 'nullable|array',
            'area.minLon' => 'required_with:area|numeric|between:-180,180',
            'area.minLat' => 'required_with:area|numeric|between:-90,90',
            'area.maxLon' => 'required_with:area|numeric|between:-180,180',
            'area.maxLat' => 'required_with:area|numeric|between:-90,90',
            'logo' => 'nullable|string|max:5000000',
            'game_mode' => 'nullable|in:standard,exercise',
            'exercise_site_id' => 'nullable|uuid|exists:exercise_sites,id',
            // Lokale UUID van een offline aangemaakte missie (zie
            // missionCloudSync.js). Puur voor idempotentie: als dezelfde
            // gebruiker 'm al eerder heeft doorgestuurd, geven we die missie
            // terug i.p.v. een tweede aan te maken.
            'client_id' => 'nullable|uuid',
        ]);

        $this->validateMapLink($data, (int) Auth::id(), (int) Auth::id());

        // Sync opnieuw geprobeerd nadat de vorige poging half slaagde (server
        // aangemaakt, lokaal opruimen mislukt)? Dan bestaat de missie al.
        if (! empty($data['client_id'])) {
            $existing = Mission::where('id', $data['client_id'])
                ->where('owner_id', Auth::id())
                ->first();

            if ($existing) {
                return response()->json(['data' => $this->present($existing, Auth::id())], 200);
            }
        }

        // Gratis (uitgeproefde) accounts mogen FREE_MISSION_LIMIT (1) missie
        // aanmaken vóór een abonnement nodig is — zelfde patroon als de
        // kaart-limiet in MapController@store. Tijdens de proefperiode of met
        // een abonnement is dit onbeperkt. Ná de idempotentie-check hierboven,
        // zodat een herhaalde offline-sync van een bestaande missie niet botst.
        if ($limited = $this->freeLimitResponse($request)) {
            return $limited;
        }

        $mission = new Mission([
            'owner_id' => Auth::id(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'planning',
            'date' => $data['date'] ?? null,
            'time' => $data['time'] ?? null,
            'ogroup' => $data['ogroup'] ?? null,
            'map' => $data['map'] ?? null,
            'area' => $data['area'] ?? null,
            'logo' => $data['logo'] ?? null,
            'game_mode' => $data['game_mode'] ?? 'standard',
            'exercise_site_id' => $data['exercise_site_id'] ?? null,
        ]);

        // Buiten de mass-assignment om: 'id' staat (terecht) niet in $fillable,
        // maar bij een offline aangemaakte missie willen we bewust dezelfde
        // UUID aanhouden als lokaal, zodat verwijzingen blijven kloppen en een
        // herhaalde sync hierboven herkend wordt. Leeg laten = HasUuids
        // genereert zelf een UUID (het normale pad).
        if (! empty($data['client_id'])) {
            $mission->id = $data['client_id'];
        }

        $mission->save();

        return response()->json(['data' => $this->present($mission, Auth::id())], 201);
    }

    /**
     * Copy a mission (owner, editor or admin). The caller becomes the owner of
     * the copy, which starts in planning: the O-group, briefing, radio nets,
     * risks and tasks (reset to todo) come along; people (collaborators, linked
     * team, task assignees), tracks, debrief, route maps, approval/lock and
     * exercise data stay with the original.
     * POST /api/v1/missions/{id}/duplicate
     */
    public function duplicate(Request $request, $id)
    {
        $source = Mission::findOrFail($id);
        $userId = (int) Auth::id();

        if (!$source->canEdit($userId)) {
            abort(403, 'You do not have permission to copy this mission');
        }

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'include_tasks' => 'sometimes|boolean',
        ]);

        if ($limited = $this->freeLimitResponse($request)) {
            return $limited;
        }

        // Een kaartkoppeling mag alleen naar een kaart van de eigenaar (zie
        // validateMapLink): bij een kopie van iemand anders valt ze weg.
        $map = $source->map;
        if ($map && ($map['source'] ?? 'server') !== 'local'
            && !\App\Models\Map::whereKey($map['id'] ?? '')->where('owner_id', $userId)->exists()) {
            $map = null;
        }

        $copy = \Illuminate\Support\Facades\DB::transaction(function () use ($source, $data, $userId, $map, $request) {
            $copy = new Mission([
                'owner_id' => $userId,
                'name' => trim($data['name'] ?? '') !== '' ? trim($data['name']) : $source->name . ' (kopie)',
                'mission_number' => $source->mission_number,
                'classification' => $source->classification,
                'description' => $source->description,
                'status' => 'planning',
                'date' => $source->date,
                'time' => $source->time,
                'ogroup' => $source->ogroup,
                'template_info' => $source->template_info,
                'map' => $map,
                'area' => $source->area,
                'logo' => $source->logo,
                'game_mode' => 'standard',
            ]);
            $copy->save();

            if ($briefing = $source->briefing) {
                $briefing->replicate()->fill(['mission_id' => $copy->id])->save();
            }
            foreach ($source->radioChannels as $channel) {
                $channel->replicate()->fill(['mission_id' => $copy->id])->save();
            }
            foreach ($source->risks as $risk) {
                $risk->replicate()->fill(['mission_id' => $copy->id])->save();
            }

            if ($request->boolean('include_tasks', true)) {
                $this->copyTasks($source, $copy, $userId);
            }

            return $copy;
        });

        return response()->json(['data' => $this->present($copy, $userId)], 201);
    }

    /**
     * Tasks (with subtasks) for a mission copy: open again, without the people
     * that were assigned (they are not on the copy), manual participants stay.
     */
    private function copyTasks(Mission $source, Mission $copy, int $userId): void
    {
        $tasks = \App\Models\MissionTask::where('mission_id', $source->id)->orderBy('order_index')->get();
        $newIds = [];
        // Ouders vóór kinderen, ook bij meerdere niveaus.
        $pending = $tasks;
        while ($pending->isNotEmpty()) {
            $ready = $pending->filter(fn ($t) => !$t->parent_id || isset($newIds[$t->parent_id]) || !$tasks->contains('id', $t->parent_id));
            if ($ready->isEmpty()) {
                break;
            }
            foreach ($ready as $t) {
                $new = $t->replicate()->fill([
                    'mission_id' => $copy->id,
                    'parent_id' => $t->parent_id ? ($newIds[$t->parent_id] ?? null) : null,
                    'assignee_user_id' => $t->assignee_user_id === $userId ? $userId : null,
                    'assigned_team_id' => null,
                    'status' => 'todo',
                    'created_by' => $userId,
                ]);
                $new->save();
                $newIds[$t->id] = $new->id;
            }
            $pending = $pending->reject(fn ($t) => isset($newIds[$t->id]));
        }
    }

    /** 402-antwoord als een gratis account zijn missielimiet al heeft bereikt, anders null. */
    private function freeLimitResponse(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if ($user && ! ($user->is_admin ?? false) && ! $user->hasPremiumAccess()
            && Mission::where('owner_id', $user->id)->count() >= \App\Models\User::FREE_MISSION_LIMIT) {
            return response()->json([
                'message' => 'Je gratis account kan ' . \App\Models\User::FREE_MISSION_LIMIT
                    . ' missie aanmaken. Neem een abonnement voor onbeperkte missies.',
                'code'    => 'subscription_required',
                'feature' => 'missions',
            ], 402);
        }

        return null;
    }

    /**
     * Update a mission (owner, editor or admin).
     * PUT /api/v1/missions/{id}
     */
    public function update(Request $request, $id)
    {
        $mission = Mission::findOrFail($id);
        $userId = Auth::id();

        if (!$mission->canEdit($userId)) {
            abort(403, 'You do not have permission to edit this mission');
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status' => ['nullable', 'string', 'max:64', 'regex:'.self::STATUS_KEY_PATTERN],
            'date' => 'nullable|date',
            'time' => 'nullable|string',
            'ogroup' => 'nullable|array',
            'map' => 'nullable|array',
            'map.id' => 'required_with:map|uuid',
            'map.source' => 'sometimes|in:server,local',
            'map.title' => 'sometimes|nullable|string|max:255',
            // Werkgebied (bounding box) waarbinnen de kaart blijft.
            'area' => 'nullable|array',
            'area.minLon' => 'required_with:area|numeric|between:-180,180',
            'area.minLat' => 'required_with:area|numeric|between:-90,90',
            'area.maxLon' => 'required_with:area|numeric|between:-180,180',
            'area.maxLat' => 'required_with:area|numeric|between:-90,90',
            'logo' => 'nullable|string|max:5000000',
            'linked_team_id' => 'nullable|uuid|exists:teams,id',
            'game_mode' => 'nullable|in:standard,exercise',
            'exercise_site_id' => 'nullable|uuid|exists:exercise_sites,id',
        ]);

        $this->validateMapLink($data, (int) $mission->owner_id, (int) $userId, $mission->map);

        // Linking / unlinking a team is a management action (owner or admin only)
        if (array_key_exists('linked_team_id', $data) && !$mission->canManage($userId)) {
            abort(403, 'Only the mission owner or admin can link a team');
        }

        // IDOR guard: a `exists:teams,id` rule only proves the team exists, not
        // that it's the actor's. Linking an arbitrary team would silently grant
        // that team's members viewer access to this mission and expose the
        // mission to them — so only a team the actor OWNS may be linked.
        if (!empty($data['linked_team_id'])) {
            $team = \App\Models\Team::find($data['linked_team_id']);
            if (!$team || !$team->isOwnedBy($userId)) {
                return response()->json([
                    'error' => 'Je kunt alleen een eigen team aan een missie koppelen.',
                ], 422);
            }
        }

        // Detecteer status-overgang voor de melding naar deelnemers. We
        // pakken de oude waarde vóór ->fill() — daarna is hij overschreven.
        $oldStatus = $mission->status;
        $newStatus = $data['status'] ?? $oldStatus;

        $mission->fill($data);
        $mission->save();

        // Specifiek: status → "complete" persisteert een notificatie naar elke
        // deelnemer met een "vul de debrief in" call-to-action. Deze rij wordt
        // door /api/v1/me/notifications opgepikt door de Meldingen-pagina en
        // de Hub-timeline. De broadcast (hieronder) blijft staan voor de
        // live-update; deze record overleeft een refresh.
        if ($newStatus === 'complete' && $oldStatus !== 'complete') {
            try {
                $mission->loadMissing(['collaborators:id,mission_id,user_id,status', 'owner:id']);
                $participantIds = collect();
                if ($mission->owner_id) $participantIds->push((int) $mission->owner_id);
                foreach ($mission->collaborators ?? [] as $c) {
                    if ($c->user_id && $c->status === 'accepted') $participantIds->push((int) $c->user_id);
                }
                foreach ($participantIds->unique()->values() as $pid) {
                    \App\Models\AppNotification::create([
                        'user_id' => $pid,
                        'type'    => 'mission.completed',
                        'title'   => 'Missie afgerond — vul de debrief in',
                        'body'    => 'Voor "' . ($mission->name ?? 'Missie') . '" staat de debrief klaar om in te vullen.',
                        'payload' => [
                            'mission_id' => (string) $mission->id,
                            'action_url' => '/missions/' . $mission->id . '?tab=debrief',
                        ],
                    ]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[mission] complete-notify failed: ' . $e->getMessage());
            }
        }

        // Status veranderd → real-time melding op het mission presence channel.
        // Wordt niet gepersisteerd (clients tonen een toast en updaten de chip).
        if ($newStatus !== $oldStatus) {
            try {
                $actor = Auth::user();
                $name  = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? ''))
                    ?: ($actor->name ?? $actor->email ?? 'Iemand');

                broadcast(new \App\Events\MissionStatusChanged(
                    (string) $mission->id,
                    (string) ($mission->name ?? 'Missie'),
                    (string) $oldStatus,
                    (string) $newStatus,
                    (int) $userId,
                    $name,
                    now()->toIso8601String(),
                ))->toOthers();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[mission] status broadcast failed: ' . $e->getMessage());
            }
        }

        return response()->json(['data' => $this->present($mission->fresh(), $userId)]);
    }

    /**
     * Dispatch the warning order: stuur elke geaccepteerde deelnemer (behalve de
     * verzender) een "New warning order"-melding. De owner/editor vult eerst het
     * waarschuwingsbevel in en dispatcht het daarna; de deelnemers lezen het in
     * en wie bewerkrechten heeft kan met de O-group beginnen.
     * POST /api/v1/missions/{id}/dispatch-warning-order
     */
    public function dispatchWarningOrder($id)
    {
        $mission = Mission::findOrFail($id);
        $userId = (int) Auth::id();

        if (!$mission->canEdit($userId)) {
            abort(403, 'You do not have permission to dispatch this mission');
        }

        $mission->loadMissing(['collaborators:id,mission_id,user_id,status']);

        $recipientIds = collect();
        if ($mission->owner_id) $recipientIds->push((int) $mission->owner_id);
        foreach ($mission->collaborators ?? [] as $c) {
            if ($c->user_id && $c->status === 'accepted') $recipientIds->push((int) $c->user_id);
        }
        // Niet naar jezelf.
        $recipientIds = $recipientIds->unique()->reject(fn ($pid) => $pid === $userId)->values();

        $count = 0;
        foreach ($recipientIds as $pid) {
            try {
                \App\Models\AppNotification::create([
                    'user_id' => $pid,
                    'type'    => 'mission.warning_order',
                    'title'   => 'New warning order',
                    'body'    => 'Er staat een nieuw waarschuwingsbevel klaar voor "' . ($mission->name ?? 'Missie') . '". Lees het in.',
                    'payload' => [
                        'mission_id' => (string) $mission->id,
                        'action_url' => '/missions/' . $mission->id . '?tab=warning',
                    ],
                ]);
                $count++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[mission] warning-order notify failed: ' . $e->getMessage());
            }
        }

        // Real-time toast naar de deelnemers op het mission presence channel.
        try {
            $actor = Auth::user();
            $name  = trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? ''))
                ?: ($actor->name ?? $actor->email ?? 'Iemand');

            broadcast(new \App\Events\WarningOrderDispatched(
                (string) $mission->id,
                (string) ($mission->name ?? 'Missie'),
                $userId,
                $name,
                now()->toIso8601String(),
            ))->toOthers();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[mission] warning-order broadcast failed: ' . $e->getMessage());
        }

        return response()->json(['ok' => true, 'notified' => $count]);
    }

    /**
     * Upload een bestand voor een "bestand"-veld in een custom O-group-
     * template (zie ogroupTemplateModel.js, veldtype "file"), of een video voor
     * een presentatiepagina (mp4/m4v/webm/mov, max 100 MB). Slaat alleen het
     * bestand op en geeft { url, name } terug — het invoegen in
     * ogroup.values.<fase>.fields.<veld> gebeurt via de gewone missie-update
     * (PUT /api/v1/missions/{id}), net als bij elk ander veldtype.
     * POST /api/v1/missions/{id}/ogroup/upload
     */
    public function uploadOgroupFile(Request $request, $id)
    {
        $mission = Mission::findOrFail($id);
        $userId = (int) Auth::id();

        if (!$mission->canEdit($userId)) {
            abort(403, 'You do not have permission to edit this mission');
        }

        // Whitelist op extensie: het bestand komt op de PUBLIEKE disk en wordt
        // met deze extensie geserveerd. Zonder whitelist kon een .php/.html/.svg
        // geüpload worden (code-executie resp. stored XSS op het API-domein).
        // Een video (presentatiepagina, speelt automatisch af) mag tot 100 MB — gelijk aan upload_max_filesize —
        // en moet ook echt een videotype zijn; alle andere bijlagen blijven 10 MB.
        $isVideo = in_array(
            strtolower((string) $request->file('file')?->getClientOriginalExtension()),
            self::OGROUP_VIDEO_EXTENSIONS,
            true,
        );
        $request->validate([
            'file' => $isVideo
                ? 'required|file|max:102400|mimetypes:' . implode(',', self::OGROUP_VIDEO_MIMES)
                : 'required|file|max:10240' // 10MB, zelfde limiet als waypoint-foto's
                    . '|extensions:' . implode(',', self::OGROUP_FILE_EXTENSIONS),
        ]);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());
        $name = \Illuminate\Support\Str::uuid() . ".{$ext}";
        $path = $file->storeAs("ogroup/{$mission->id}", $name, 'public');

        \App\Models\UserUpload::record($userId, $path, (int) $file->getSize(), $file->getClientMimeType(), 'ogroup');

        return response()->json([
            'url'  => \Illuminate\Support\Facades\Storage::disk('public')->url($path),
            'name' => $file->getClientOriginalName(),
        ], 201);
    }

    /**
     * Delete a mission (owner only).
     * DELETE /api/v1/missions/{id}
     */
    public function destroy($id)
    {
        $mission = Mission::findOrFail($id);

        if (!$mission->isOwnedBy(Auth::id())) {
            abort(403, 'Only the mission owner can delete it');
        }

        $mission->delete();

        return response()->json(['message' => 'Mission deleted']);
    }

    /** Linking delegates map access to mission members; only the common owner may do it. */
    private function validateMapLink(array $data, int $missionOwner, int $actor, ?array $current = null): void
    {
        if (!array_key_exists('map', $data)) return;
        $link = $data['map'];
        if ($link != $current && $actor !== $missionOwner) {
            abort(403, 'Alleen de eigenaar kan de missiekaart wijzigen.');
        }
        if (!$link || ($link['source'] ?? 'server') === 'local') return;
        $owned = \App\Models\Map::whereKey($link['id'] ?? '')
            ->where('owner_id', $missionOwner)->exists();
        if (!$owned) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'map' => 'Koppel alleen een kaart die eigendom is van de missie-eigenaar.',
            ]);
        }
    }

    /**
     * Shape a mission for the frontend, including the viewer's role + rights.
     */
    private function present(Mission $mission, int $userId, bool $includeLogo = true): array
    {
        $role = $mission->roleFor($userId);

        // Linked team summary (id, name, color, member count). Use the
        // eager-loaded relation when present (mission list) to avoid a per-row
        // query; fall back to a direct lookup for the single-mission path.
        $linkedTeam = null;
        if ($mission->linked_team_id) {
            $team = $mission->relationLoaded('linkedTeam')
                ? $mission->linkedTeam
                : $mission->linkedTeam()->withCount('members')->first();
            if ($team) {
                $linkedTeam = [
                    'id'            => $team->id,
                    'name'          => $team->name,
                    'color'         => $team->color,
                    'members_count' => $team->members_count ?? $team->members()->count(),
                ];
            }
        }

        $out = [
            'id' => $mission->id,
            'owner_id' => $mission->owner_id,
            'linked_team_id' => $mission->linked_team_id,
            'linked_team' => $linkedTeam,
            'name' => $mission->name,
            'description' => $mission->description,
            'status' => $mission->status,
            'date' => $mission->date?->toDateString(),
            'time' => $mission->time,
            'ogroup' => $mission->ogroup,
            'template_info' => $mission->template_info,
            'map' => $mission->map,
            'area' => $mission->area,
            // Oefening-modus: laat de client het eigen ExercisePanel tonen.
            'game_mode' => $mission->game_mode,
            'exercise_site_id' => $mission->exercise_site_id,
            'created_at' => $mission->created_at,
            'updated_at' => $mission->updated_at,
            // Access info for UI gating
            'my_role' => $role,
            'is_owner' => $role === 'owner',
            'can_edit' => in_array($role, ['owner', 'editor', 'admin'], true),
            'can_manage' => in_array($role, ['owner', 'admin'], true),
        ];

        // Always expose the collaborator roster as an array so the client can
        // safely read `mission.collaborators.length` (e.g. a participant-count
        // badge). index() eager-loads collaborators.user + owner with the exact
        // same shape used here, so loadMissing() is a true no-op on the list
        // path (no N+1); the single-mission paths load it on demand. Kept to a
        // minimal, non-sensitive shape — the full management view with e-mails
        // lives behind the owner/admin-only GET /missions/{id}/collaborators
        // endpoint.
        $mission->loadMissing(['collaborators.user:id,first_name,last_name,avatar_path', 'owner:id,first_name,last_name,avatar_path']);
        $out['collaborators'] = $mission->collaborators->map(fn ($c) => [
            'id'      => $c->id,
            'user_id' => $c->user_id,
            'role'    => $c->role,
            'status'  => $c->status,
        ])->values();

        // Lichte deelnemers-roster (eigenaar + geaccepteerde medewerkers) met
        // naam + avatar voor de missie-header. Zichtbaar voor iedereen die de
        // missie mag inzien; e-mails blijven achter het owner/admin-only
        // collaborators-endpoint. Ontdubbeld op user-id.
        $members = [];
        if ($mission->owner) {
            $members[] = [
                'id'         => $mission->owner->id,
                'name'       => $mission->owner->full_name,
                'avatar_url' => $mission->owner->avatar_url,
                'role'       => 'owner',
            ];
        }
        foreach ($mission->collaborators as $c) {
            if ($c->status !== 'accepted' || !$c->user) {
                continue;
            }
            $members[] = [
                'id'         => $c->user->id,
                'name'       => $c->user->full_name,
                'avatar_url' => $c->user->avatar_url,
                'role'       => $c->role,
            ];
        }
        $out['members'] = collect($members)->unique('id')->values();

        if ($includeLogo) {
            $out['logo'] = $mission->logo;
        }

        return $out;
    }
}
