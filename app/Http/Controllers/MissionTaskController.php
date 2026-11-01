<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Models\MissionTask;
use App\Models\MissionTaskTemplate;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Mission tasks — kort actiepunten met optionele toewijzing.
 *
 *   • Lezen mag iedereen die Mission::hasAccess($uid) is — collaborators
 *     en eigenaar.
 *   • Schrijven (create/update/delete/reorder) vereist Mission::canEdit($uid)
 *     zodat een viewer er geen ratjetoe van kan maken.
 *   • Toewijzing: óf 1+ individuele leden (mission_task_user pivot), óf een heel
 *     team (assigned_team_id). Wederzijds exclusief. De legacy-kolom
 *     assignee_user_id blijft gevuld met de eerste assignee voor oudere lezers
 *     (o.a. de PDF-export).
 *   • Subtaken via parent_id; volgorde per sibling-groep via order_index met een
 *     aparte reorder-endpoint zodat de drag-drop roundtrip klein blijft.
 */
class MissionTaskController extends Controller
{
    /** Eager-load set voor een nette presentatie zonder N+1. */
    private const WITH = [
        'assignee:id,first_name,last_name,email',
        'assignees:id,first_name,last_name,email',
        'team:id,name,color',
        'teamProgress.team:id,name,color,owner_id',
    ];

    public function index(string $missionId)
    {
        $uid = Auth::id();
        $mission = Mission::findOrFail($missionId);
        $this->authorizeRead($mission, $uid);

        $tasks = MissionTask::with(self::WITH)
            ->where('mission_id', $mission->id)
            ->orderBy('order_index')
            ->orderBy('created_at')
            ->get();

        return response()->json(['tasks' => $tasks->map(fn ($t) => $this->present($t))]);
    }

    /**
     * GET /me/tasks — alle taken die aan de ingelogde gebruiker zijn gekoppeld,
     * over alle missies heen. Voedt de "Mijn taken"-widget op de Hub.
     * Gekoppeld = directe toewijzing (legacy assignee_user_id óf de assignees-
     * pivot) óf toegewezen aan een team waar de gebruiker geaccepteerd lid van is.
     */
    public function mine(Request $request)
    {
        $uid = Auth::id();

        $teamIds = TeamMember::activeTeamIdsQuery($uid)->pluck('team_id')
            ->merge(Team::where('owner_id', $uid)->pluck('id'))->unique();

        $tasks = MissionTask::query()
            ->with(['mission:id,name,status', 'teamProgress' => fn ($p) => $p->whereIn('team_id', $teamIds)])
            ->whereHas('mission', fn ($m) => $m->participatedBy($uid))
            ->where(function ($q) use ($uid, $teamIds) {
                $q->where('assignee_user_id', $uid)
                  ->orWhereHas('assignees', fn ($a) => $a->where('users.id', $uid));
                if ($teamIds->isNotEmpty()) {
                    $q->orWhereIn('assigned_team_id', $teamIds)
                      ->orWhereHas('teamProgress', fn ($p) => $p->whereIn('team_id', $teamIds));
                }
            })
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'tasks' => $tasks->map(fn ($t) => [
                'id'       => $t->id,
                'title'    => $t->title,
                'status'   => $t->teamProgress->isNotEmpty()
                    ? ($t->teamProgress->every(fn ($p) => $p->status === 'done') ? 'done'
                        : ($t->teamProgress->contains(fn ($p) => $p->status !== 'todo') ? 'doing' : 'todo'))
                    : $t->status,
                'priority' => $t->priority,
                'due_at'   => optional($t->due_at)?->toIso8601String(),
                'mission'  => $t->mission ? [
                    'id'     => $t->mission->id,
                    'name'   => $t->mission->name,
                    'status' => $t->mission->status,
                ] : null,
            ]),
        ]);
    }

    public function store(Request $request, string $missionId)
    {
        $uid = Auth::id();
        $mission = Mission::findOrFail($missionId);
        $this->authorizeWrite($mission, $uid);

        $data = $request->validate($this->rules());

        // parent_id moet bij dezelfde missie horen (geen subtaak onder een
        // taak van een andere missie).
        $parentId = $this->resolveParentId($mission, $data['parent_id'] ?? null);

        // order_index = aan het eind van de huidige sibling-groep.
        $maxOrder = (int) MissionTask::where('mission_id', $mission->id)
            ->where('parent_id', $parentId)
            ->max('order_index');

        $task = DB::transaction(function () use ($mission, $parentId, $data, $maxOrder, $uid, $request) {
            $task = MissionTask::create([
                'mission_id'  => $mission->id,
                'parent_id'   => $parentId,
                'phase_key'   => $data['phase_key'] ?? null,
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'due_at'      => $data['due_at'] ?? null,
                'status'      => $data['status'] ?? 'todo',
                'priority'    => $data['priority'] ?? 'normal',
                'order_index' => $maxOrder + 1,
                'created_by'  => $uid,
            ]);

            $this->applyAssignment($task, $request, $data);
            return $task;
        });

        return response()->json(['task' => $this->present($task->fresh(self::WITH))], 201);
    }

    public function update(Request $request, string $missionId, string $taskId)
    {
        $uid = Auth::id();
        $mission = Mission::findOrFail($missionId);
        $this->authorizeWrite($mission, $uid);

        $task = MissionTask::where('mission_id', $mission->id)->findOrFail($taskId);

        $data = $request->validate($this->rules(true));

        DB::transaction(function () use ($task, $request, $data) {
            $task->setRawAttributes(MissionTask::whereKey($task->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            abort_if(isset($data['status']) && $task->teamProgress()->exists(), 422,
                'Werk de status per team bij.');
            $task->fill(array_intersect_key($data, array_flip([
                'title', 'description', 'due_at', 'status', 'priority', 'phase_key',
            ])))->save();
            $this->applyAssignment($task, $request, $data);
        });

        return response()->json(['task' => $this->present($task->fresh(self::WITH))]);
    }

    public function destroy(string $missionId, string $taskId)
    {
        $uid = Auth::id();
        $mission = Mission::findOrFail($missionId);
        $this->authorizeWrite($mission, $uid);

        $task = MissionTask::where('mission_id', $mission->id)->findOrFail($taskId);

        // Subtaken mee verwijderen (vangnet naast de nullOnDelete-FK).
        MissionTask::where('mission_id', $mission->id)->where('parent_id', $task->id)->delete();
        $task->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /missions/{id}/tasks/reorder  { order: [taskId, taskId, ...] }
     * Schrijft order_index in één transactie. De FE stuurt één sibling-groep
     * (top-level óf de kinderen van één ouder) in de nieuwe volgorde.
     */
    public function reorder(Request $request, string $missionId)
    {
        $uid = Auth::id();
        $mission = Mission::findOrFail($missionId);
        $this->authorizeWrite($mission, $uid);

        $data = $request->validate([
            'order'   => 'required|array|min:1',
            'order.*' => 'required|uuid',
        ]);

        DB::transaction(function () use ($mission, $data) {
            foreach ($data['order'] as $i => $tid) {
                MissionTask::where('mission_id', $mission->id)
                    ->where('id', $tid)
                    ->update(['order_index' => $i]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /**
     * GET /missions/{id}/tasks/assignees
     * De personen die je aan een taak kunt koppelen (eigenaar + geaccepteerde
     * collaborators + leden van het gekoppelde team mét account) plus het
     * gekoppelde team zelf — alles wat de moderne toewijs-select nodig heeft.
     */
    public function assignees(string $missionId)
    {
        $uid = Auth::id();
        $mission = Mission::findOrFail($missionId);
        $this->authorizeRead($mission, $uid);

        $users = collect();

        $mission->loadMissing('owner:id,first_name,last_name,email');
        if ($mission->owner) {
            $users->push($mission->owner);
        }

        $users = $users->merge(
            $mission->collaborators()
                ->where('status', 'accepted')
                ->whereNotNull('user_id')
                ->with('user:id,first_name,last_name,email')
                ->get()
                ->pluck('user')
                ->filter()
        );

        $team = null;
        $missionTeamIds = $mission->teamIds();
        if ($missionTeamIds) {
            $team = Team::find($mission->linked_team_id ?: $missionTeamIds[0]);
            $users = $users->merge(
                TeamMember::whereIn('team_id', $missionTeamIds)
                    ->where('status', TeamMember::STATUS_ACTIVE)
                    ->whereNotNull('user_id')
                    ->with('user:id,first_name,last_name,email')
                    ->get()
                    ->pluck('user')
                    ->filter()
            );
        }

        $people = $users
            ->filter()
            ->unique('id')
            ->map(fn ($u) => [
                'id'    => $u->id,
                'name'  => $this->userName($u),
                'email' => $u->email,
            ])
            ->values()
            ->all();

        $teams = Team::whereIn('id', $missionTeamIds)->with(['activeMembers.user', 'owner'])->get();
        return response()->json([
            'teams' => $teams->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'color' => $t->color,
                'users' => $t->activeMembers->pluck('user')->push($t->owner)->filter()->unique('id')
                    ->map(fn ($u) => ['id' => $u->id, 'name' => $this->userName($u)])->values(),
            ])->values(),
            'users' => $people,
            'team'  => $team ? [
                'id'    => $team->id,
                'name'  => $team->name,
                'color' => $team->color ?? null,
            ] : null,
        ]);
    }

    /**
     * POST /missions/{id}/tasks/apply-template  { template_id: uuid }
     * Plakt alle items van een template als nieuwe taken aan het einde van de
     * top-level lijst. Alleen templates die de actor bezit zijn toegestaan.
     */
    public function applyTemplate(Request $request, string $missionId)
    {
        $uid = Auth::id();
        $mission = Mission::findOrFail($missionId);
        $this->authorizeWrite($mission, $uid);

        $data = $request->validate([
            'template_id'      => 'required|uuid',
            // Optioneel: alle nieuwe taken aan deze persoon/team toewijzen.
            'assignee_user_id' => 'nullable|integer|exists:users,id',
            'assigned_team_id' => 'nullable|uuid|exists:teams,id',
        ]);

        $template = MissionTaskTemplate::where('owner_id', $uid)
            ->where('id', $data['template_id'])
            ->with('items')
            ->first();

        if (! $template) {
            return response()->json(['message' => 'Template niet gevonden of geen toegang.'], 404);
        }

        $maxOrder = (int) MissionTask::where('mission_id', $mission->id)
            ->whereNull('parent_id')
            ->max('order_index');
        $created = [];

        $teamId   = $data['assigned_team_id'] ?? null;
        abort_if($teamId && !in_array($teamId, $mission->teamIds(), true), 422, 'Selecteer een team dat aan deze missie is gekoppeld.');
        $singleId = $teamId ? null : ($data['assignee_user_id'] ?? null);

        DB::transaction(function () use ($template, $mission, $uid, $teamId, $singleId, &$maxOrder, &$created) {
            foreach ($template->items as $item) {
                $maxOrder++;
                $task = MissionTask::create([
                    'mission_id'       => $mission->id,
                    'parent_id'        => null,
                    'title'            => $item->title,
                    'description'      => $item->description,
                    'assignee_user_id' => $singleId,
                    'assigned_team_id' => $teamId,
                    'status'           => 'todo',
                    'order_index'      => $maxOrder,
                    'created_by'       => $uid,
                ]);
                if ($teamId) {
                    $task->teamProgress()->create(['team_id' => $teamId, 'status' => 'todo']);
                }
                if ($singleId) {
                    $task->assignees()->sync([$singleId]);
                }
                $created[] = $task->id;
            }
        });

        // Registreer welke template is toegepast zodat de missie-UI die kan
        // tonen en (via template-beheer) openen.
        $mission->update([
            'template_info' => [
                'id'         => $template->id,
                'name'       => $template->name,
                'applied_at' => now()->toIso8601String(),
            ],
        ]);

        $tasks = MissionTask::with(self::WITH)
            ->whereIn('id', $created)
            ->orderBy('order_index')
            ->get();

        return response()->json([
            'inserted' => count($created),
            'tasks'    => $tasks->map(fn ($t) => $this->present($t)),
        ], 201);
    }

    private function canUpdateTeam(Mission $mission, ?Team $team, int $uid): bool
    {
        if (!$team || !in_array($team->id, $mission->teamIds(), true)) return false;
        return $mission->canEdit($uid) || (int) $team->owner_id === $uid
            || TeamMember::activeTeamIdsQuery($uid)->where('team_id', $team->id)->exists();
    }

    private function aggregateTeamStatus(MissionTask $task): void
    {
        $statuses = $task->teamProgress()->pluck('status');
        $task->status = $statuses->isNotEmpty() && $statuses->every(fn ($s) => $s === 'done')
            ? 'done' : ($statuses->contains(fn ($s) => $s !== 'todo') ? 'doing' : 'todo');
        $task->save();
    }

    public function updateTeamProgress(Request $request, string $missionId, string $taskId, string $teamId)
    {
        $mission = Mission::findOrFail($missionId);
        $this->authorizeRead($mission, (int) Auth::id());
        $task = MissionTask::where('mission_id', $mission->id)->findOrFail($taskId);
        $team = Team::findOrFail($teamId);
        abort_unless($this->canUpdateTeam($mission, $team, (int) Auth::id()), 403);
        $data = $request->validate([
            'status' => 'sometimes|required|in:todo,doing,done',
            'comment' => 'sometimes|required|string|max:4000',
        ]);
        DB::transaction(function () use ($task, $teamId, $data) {
            // Serialize all updates to a task, including status aggregation.
            MissionTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $progress = $task->teamProgress()->where('team_id', $teamId)->lockForUpdate()->firstOrFail();
            if (isset($data['status'])) $progress->status = $data['status'];
            if (isset($data['comment']) && trim($data['comment']) !== '') {
                $comments = $progress->comments ?? [];
                $comments[] = [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'user_id' => Auth::id(), 'name' => $this->userName(Auth::user()),
                    'body' => trim($data['comment']), 'created_at' => now()->toIso8601String(),
                ];
                $progress->comments = $comments;
            }
            $progress->save();
            $this->aggregateTeamStatus($task);
        });
        return response()->json(['task' => $this->present($task->fresh(self::WITH))]);
    }

    // ── Assignment ────────────────────────────────────────────

    /**
     * Pas de toewijzing toe op basis van de request. Wederzijds exclusief:
     *   • assigned_team_id (non-null)  → heel team; individuele assignees leeg.
     *   • assignee_user_ids / assignee_user_id → individuele leden; team leeg.
     * Wanneer geen enkele toewijs-sleutel in de request zit laten we de
     * bestaande toewijzing met rust (zo blijft een PATCH van enkel `status`
     * de assignees ongemoeid).
     */
    private function applyAssignment(MissionTask $task, Request $request, array $data): void
    {
        $hasTeamKey  = $request->has('assigned_team_id') || $request->has('all_teams');
        $hasUsersKey = $request->has('assignee_user_ids') || $request->has('assignee_user_id');
        $hasPartKey  = $request->has('assignee_participants');

        if (! $hasTeamKey && ! $hasUsersKey && ! $hasPartKey) {
            return; // niets aan toewijzing veranderen
        }

        // Handmatige deelnemers (zonder account): alleen aanpassen als ze
        // meekomen; een PATCH met enkel gebruikers laat ze ongemoeid.
        if ($hasPartKey) {
            $task->forceFill(['assignee_participants' => $this->normalizeParticipants($data['assignee_participants'] ?? [])]);
        }

        $allTeams = $request->has('all_teams') ? (bool) ($data['all_teams'] ?? false) : ($hasTeamKey ? false : $task->all_teams);
        $teamId = $hasTeamKey ? ($data['assigned_team_id'] ?? null) : $task->assigned_team_id;
        $teamIds = $allTeams ? $task->mission->teamIds() : ($teamId ? [$teamId] : []);
        if ($allTeams || $teamId) {
            abort_if(!$teamIds || array_diff($teamIds, $task->mission->teamIds()), 422,
                'Selecteer een team dat aan deze missie is gekoppeld.');
            // Keep the original recipient snapshot when editing an all-teams task.
            if ($allTeams && $task->all_teams) {
                $teamIds = $task->teamProgress()->pluck('team_id')->all();
            }
        }

        // Normaliseer de individuele leden naar een unieke int-lijst.
        $userIds = [];
        if ($request->has('assignee_user_ids')) {
            $userIds = collect($data['assignee_user_ids'] ?? [])
                ->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
        } elseif ($request->has('assignee_user_id')) {
            $single = $data['assignee_user_id'] ?? null;
            $userIds = $single ? [(int) $single] : [];
        } elseif (! $hasTeamKey) {
            // geen gebruikers-sleutel (alleen deelnemers veranderd); behoud bestaande leden
            $userIds = $task->assignees()->pluck('users.id')->all();
        }

        if ($allTeams || $teamId) {
            // Heel team wint → individuele leden wissen.
            $task->assignees()->sync([]);
            $task->forceFill([
                'assigned_team_id'      => $allTeams ? null : $teamId,
                'all_teams'             => $allTeams,
                'assignee_user_id'      => null,
                'assignee_participants' => null,
            ])->save();
            $task->teamProgress()->whereNotIn('team_id', $teamIds)->delete();
            foreach ($teamIds as $id) {
                $task->teamProgress()->firstOrCreate(['team_id' => $id], ['status' => 'todo']);
            }
            $this->aggregateTeamStatus($task);
            return;
        }

        $hadTeams = $task->teamProgress()->exists();
        $task->teamProgress()->delete();
        $task->all_teams = false;
        if ($hadTeams) $task->status = 'todo';
        // Individuele leden (kan leeg zijn = niemand).
        $task->assignees()->sync($userIds);
        $task->forceFill([
            'assigned_team_id' => null,
            'assignee_user_id' => $userIds[0] ?? null, // legacy/eerste
        ])->save();
    }

    /** Handmatige deelnemers → unieke [{ name, role }] (op naam, hoofdletterongevoelig); leeg = null. */
    private function normalizeParticipants($list): ?array
    {
        $out = [];
        foreach ((array) $list as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '' || isset($out[mb_strtolower($name)])) {
                continue;
            }
            $role = trim((string) ($p['role'] ?? ''));
            $out[mb_strtolower($name)] = ['name' => $name, 'role' => $role !== '' ? $role : null];
        }
        return $out ? array_values($out) : null;
    }

    /** Gemeenschappelijke validatieregels voor store/update. */
    private function rules(bool $isUpdate = false): array
    {
        return [
            'title'               => ($isUpdate ? 'sometimes|required' : 'required') . '|string|max:255',
            'description'         => 'nullable|string',
            'assignee_user_ids'   => 'nullable|array',
            'assignee_user_ids.*' => 'integer|exists:users,id',
            'assignee_user_id'    => 'nullable|integer|exists:users,id', // legacy single
            'assigned_team_id'    => 'nullable|uuid|exists:teams,id',
            'all_teams'           => 'sometimes|boolean',
            'assignee_participants'        => 'nullable|array|max:25',
            'assignee_participants.*.name' => 'required|string|max:120',
            'assignee_participants.*.role' => 'nullable|string|max:120',
            'parent_id'           => 'nullable|uuid|exists:mission_tasks,id',
            'phase_key'           => 'nullable|string|max:96',
            'due_at'              => 'nullable|date',
            'status'              => ($isUpdate ? 'sometimes' : 'nullable') . '|in:todo,doing,done',
            'priority'            => ($isUpdate ? 'sometimes' : 'nullable') . '|in:urgent,high,normal,low',
        ];
    }

    /** parent_id alleen accepteren als die taak bij dezelfde missie hoort. */
    private function resolveParentId(Mission $mission, ?string $parentId): ?string
    {
        if (! $parentId) {
            return null;
        }
        $ok = MissionTask::where('mission_id', $mission->id)->where('id', $parentId)->exists();
        return $ok ? $parentId : null;
    }

    // ── Helpers ───────────────────────────────────────────────

    protected function authorizeRead(Mission $mission, int $uid): void
    {
        if (! $mission->hasAccess($uid)) {
            abort(403, 'Geen toegang tot deze missie.');
        }
    }

    protected function authorizeWrite(Mission $mission, int $uid): void
    {
        if (! $mission->canEdit($uid)) {
            abort(403, 'Geen bewerkrecht op deze missie.');
        }
    }

    private function userName(User $u): string
    {
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        return $name !== '' ? $name : ($u->email ?? 'Onbekend');
    }

    protected function present(MissionTask $t): array
    {
        $assignees = $t->relationLoaded('assignees') ? $t->assignees : $t->assignees()->get();

        $people = $assignees->map(fn ($u) => [
            'id'    => $u->id,
            'name'  => $this->userName($u),
            'email' => $u->email,
        ])->values();

        // Fallback op de legacy single-kolom (bv. oude rijen vóór de pivot).
        if ($people->isEmpty() && $t->assignee) {
            $people = collect([[
                'id'    => $t->assignee->id,
                'name'  => $this->userName($t->assignee),
                'email' => $t->assignee->email,
            ]]);
        }

        return [
            'id'           => $t->id,
            'mission_id'   => $t->mission_id,
            'parent_id'    => $t->parent_id,
            'phase_key'    => $t->phase_key,
            'title'        => $t->title,
            'description'  => $t->description,
            'status'       => $t->status,
            'priority'     => $t->priority ?: 'normal',
            'order_index'  => $t->order_index,
            'due_at'       => optional($t->due_at)->toIso8601String(),
            'assignees'    => $people->all(),
            // Legacy single (eerste assignee) — o.a. de PDF-export leest dit.
            'assignee'     => $people->first() ?: null,
            // Handmatige deelnemers (zonder account) — momentopname [{ name, role }].
            'participants' => collect($t->assignee_participants ?? [])
                ->map(fn ($p) => ['name' => $p['name'] ?? '', 'role' => $p['role'] ?? null])
                ->filter(fn ($p) => $p['name'] !== '')
                ->values()->all(),
            'all_teams'    => (bool) $t->all_teams,
            'team_progress' => $t->teamProgress->map(fn ($p) => [
                'team_id' => $p->team_id, 'name' => $p->team?->name ?? 'Team',
                'color' => $p->team?->color, 'status' => $p->status,
                'comments' => $p->comments ?? [],
                'can_update' => $this->canUpdateTeam($t->mission, $p->team, (int) Auth::id()),
            ])->values()->all(),
            'team'         => $t->team ? [
                'id'    => $t->team->id,
                'name'  => $t->team->name,
                'color' => $t->team->color ?? null,
            ] : null,
            'created_at'   => optional($t->created_at)->toIso8601String(),
            'updated_at'   => optional($t->updated_at)->toIso8601String(),
        ];
    }
}
