<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Models\MissionTask;
use App\Models\MissionTaskTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mission tasks — kort actiepunten met optionele assignee/team-koppeling.
 *
 *   • Lezen mag iedereen die Mission::hasAccess($uid) is — collaborators
 *     en eigenaar.
 *   • Schrijven (create/update/delete/reorder) vereist Mission::canEdit($uid)
 *     zodat een viewer er geen ratjetoe van kan maken.
 *   • Eén taak per keer + een aparte reorder-endpoint (in plaats van full
 *     update per drag-drop) zodat de roundtrip klein blijft.
 */
class MissionTaskController extends Controller
{
    public function index(string $missionId)
    {
        $uid = Auth::id();
        $mission = Mission::findOrFail($missionId);
        $this->authorizeRead($mission, $uid);

        $tasks = MissionTask::with([
            'assignee:id,first_name,last_name,email',
            'team:id,name,color',
        ])
            ->where('mission_id', $mission->id)
            ->orderBy('order_index')
            ->orderBy('created_at')
            ->get();

        return response()->json(['tasks' => $tasks->map(fn ($t) => $this->present($t))]);
    }

    public function store(Request $request, string $missionId)
    {
        $uid = Auth::id();
        $mission = Mission::findOrFail($missionId);
        $this->authorizeWrite($mission, $uid);

        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'assignee_user_id' => 'nullable|integer|exists:users,id',
            'assigned_team_id' => 'nullable|uuid|exists:teams,id',
            'due_at'           => 'nullable|date',
            'status'           => 'nullable|in:todo,doing,done',
        ]);

        // order_index = aan het eind van de huidige lijst.
        $maxOrder = (int) MissionTask::where('mission_id', $mission->id)->max('order_index');

        $task = MissionTask::create([
            'mission_id'       => $mission->id,
            'title'            => $data['title'],
            'description'      => $data['description'] ?? null,
            'assignee_user_id' => $data['assignee_user_id'] ?? null,
            'assigned_team_id' => $data['assigned_team_id'] ?? null,
            'due_at'           => $data['due_at'] ?? null,
            'status'           => $data['status'] ?? 'todo',
            'order_index'      => $maxOrder + 1,
            'created_by'       => $uid,
        ]);

        return response()->json(['task' => $this->present($task->fresh(['assignee', 'team']))], 201);
    }

    public function update(Request $request, string $missionId, string $taskId)
    {
        $uid = Auth::id();
        $mission = Mission::findOrFail($missionId);
        $this->authorizeWrite($mission, $uid);

        $task = MissionTask::where('mission_id', $mission->id)->findOrFail($taskId);

        $data = $request->validate([
            'title'            => 'sometimes|required|string|max:255',
            'description'      => 'nullable|string',
            'assignee_user_id' => 'nullable|integer|exists:users,id',
            'assigned_team_id' => 'nullable|uuid|exists:teams,id',
            'due_at'           => 'nullable|date',
            'status'           => 'sometimes|in:todo,doing,done',
        ]);

        $task->fill($data)->save();

        return response()->json(['task' => $this->present($task->fresh(['assignee', 'team']))]);
    }

    public function destroy(string $missionId, string $taskId)
    {
        $uid = Auth::id();
        $mission = Mission::findOrFail($missionId);
        $this->authorizeWrite($mission, $uid);

        $task = MissionTask::where('mission_id', $mission->id)->findOrFail($taskId);
        $task->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * POST /missions/{id}/tasks/reorder  { order: [taskId, taskId, ...] }
     * Schrijft order_index in één transactie zodat een halve update niet
     * de lijst kan corrumperen.
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
     * POST /missions/{id}/apply-template  { template_id: uuid }
     * Plakt alle items van een template als nieuwe taken aan het einde
     * van de lijst. Alleen templates die de actor bezit zijn toegestaan
     * (anders zou je geleende templates kunnen toepassen op andermans
     * missies).
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

        $maxOrder = (int) MissionTask::where('mission_id', $mission->id)->max('order_index');
        $created = [];

        DB::transaction(function () use ($template, $mission, $uid, $data, &$maxOrder, &$created) {
            foreach ($template->items as $item) {
                $maxOrder++;
                $task = MissionTask::create([
                    'mission_id'       => $mission->id,
                    'title'            => $item->title,
                    'description'      => $item->description,
                    'assignee_user_id' => $data['assignee_user_id'] ?? null,
                    'assigned_team_id' => $data['assigned_team_id'] ?? null,
                    'status'           => 'todo',
                    'order_index'      => $maxOrder,
                    'created_by'       => $uid,
                ]);
                $created[] = $task->id;
            }
        });

        $tasks = MissionTask::with(['assignee:id,first_name,last_name,email', 'team:id,name,color'])
            ->whereIn('id', $created)
            ->orderBy('order_index')
            ->get();

        return response()->json([
            'inserted' => count($created),
            'tasks'    => $tasks->map(fn ($t) => $this->present($t)),
        ], 201);
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

    protected function present(MissionTask $t): array
    {
        return [
            'id'               => $t->id,
            'mission_id'       => $t->mission_id,
            'title'            => $t->title,
            'description'      => $t->description,
            'status'           => $t->status,
            'order_index'      => $t->order_index,
            'due_at'           => optional($t->due_at)->toIso8601String(),
            'assignee'         => $t->assignee ? [
                'id'    => $t->assignee->id,
                'name'  => trim(($t->assignee->first_name ?? '') . ' ' . ($t->assignee->last_name ?? ''))
                          ?: $t->assignee->email,
                'email' => $t->assignee->email,
            ] : null,
            'team'             => $t->team ? [
                'id'    => $t->team->id,
                'name'  => $t->team->name,
                'color' => $t->team->color ?? null,
            ] : null,
            'created_at'       => optional($t->created_at)->toIso8601String(),
            'updated_at'       => optional($t->updated_at)->toIso8601String(),
        ];
    }
}
