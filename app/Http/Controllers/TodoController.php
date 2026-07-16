<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use App\Models\TodoAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Taken-omgeving: de gedeelde takenlijst (ClickUp-stijl kanban), opgeslagen in
 * Laravel. Twee toegangswegen op dezelfde tabel:
 *
 *   • admin*  — voor het MilMap-admin paneel (Sanctum + admin.auth). Volledige
 *               CRUD met alle taak-velden (labels, prioriteit, toegewezene,
 *               app-versie, deadline-week, workflow-status).
 *   • deploy* — voor de headless deploy-app queue-runner (X-Deploy-Token). Die
 *               spreekt nog de legacy queue-status (pending/queued/running/
 *               done/failed); we vertalen aan de rand van/naar de 8 workflow-
 *               fases zodat beide werelden samenwerken.
 */
class TodoController extends Controller
{
    // ── Admin (MilMap-paneel) ──────────────────────────────────────────

    public function adminIndex(Request $request)
    {
        $request->validate([
            'q'      => 'nullable|string|max:255',
            'status' => ['nullable', Rule::in(Todo::STATUSES)],
        ]);

        $query = Todo::query()->with(['creator', 'labels', 'attachments']);

        if ($qs = $request->query('q')) {
            $query->where(function ($w) use ($qs) {
                $w->where('title', 'like', '%' . $qs . '%')
                    ->orWhere('description', 'like', '%' . $qs . '%');
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $todos = $query->orderBy('position')->orderByDesc('created_at')->get();

        return response()->json([
            'todos'  => $todos->map(fn ($t) => $t->toApiArray())->values(),
            'counts' => $this->statusCounts(),
        ]);
    }

    public function adminStore(Request $request)
    {
        $validated = $this->validateTask($request, true);

        $todo = Todo::create([
            'title'             => $validated['title'],
            'description'       => $validated['description'] ?? null,
            'repo'              => $validated['repo'] ?? 'frontend',
            'mode'              => $validated['mode'] ?? 'fix',
            'status'            => $validated['status'] ?? 'backlog',
            'priority'          => $validated['priority'] ?? 'normaal',
            'assignee'          => $validated['assignee'] ?? null,
            'app_version'       => $validated['appVersion'] ?? null,
            'deadline_week'     => $validated['deadlineWeek'] ?? null,
            'deadline_year'     => $validated['deadlineYear'] ?? null,
            'source'            => 'admin',
            'created_by'        => Auth::id(),
            'status_changed_at' => now(),
        ]);

        if (array_key_exists('labels', $validated)) {
            $todo->labels()->sync($validated['labels'] ?? []);
        }

        return response()->json(['todo' => $todo->fresh(['creator', 'labels', 'attachments'])->toApiArray()], 201);
    }

    public function adminUpdate(Request $request, string $id)
    {
        $todo = Todo::findOrFail($id);
        $validated = $this->validateTask($request, false);

        if (array_key_exists('status', $validated) && $validated['status'] !== $todo->status) {
            $todo->status_changed_at = now();
            $todo->completed_at = $validated['status'] === 'productie' ? now() : null;
        }

        foreach ([
            'title' => 'title', 'description' => 'description', 'repo' => 'repo',
            'mode' => 'mode', 'status' => 'status', 'priority' => 'priority',
            'assignee' => 'assignee', 'appVersion' => 'app_version',
            'deadlineWeek' => 'deadline_week', 'deadlineYear' => 'deadline_year',
            'position' => 'position',
        ] as $in => $col) {
            if (array_key_exists($in, $validated)) {
                $todo->{$col} = $validated[$in];
            }
        }
        $todo->save();

        if (array_key_exists('labels', $validated)) {
            $todo->labels()->sync($validated['labels'] ?? []);
        }

        return response()->json(['todo' => $todo->fresh(['creator', 'labels', 'attachments'])->toApiArray()]);
    }

    public function adminDestroy(string $id)
    {
        Todo::where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    // ── Bijlagen ────────────────────────────────────────────────────────

    /** POST /api/v1/admin/todos/{id}/attachments — één of meer bestanden. */
    public function uploadAttachment(Request $request, string $id)
    {
        $todo = Todo::findOrFail($id);
        $request->validate([
            'files'   => 'required|array|max:10',
            'files.*' => 'file|max:20480', // 20 MB per bestand
        ]);

        $created = [];
        foreach ($request->file('files', []) as $file) {
            $path = $file->store("todo-attachments/{$todo->id}", 'public');
            $att = $todo->attachments()->create([
                'path'          => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime'          => $file->getMimeType(),
                'size'          => $file->getSize(),
            ]);
            $created[] = $att->toApiArray();
        }

        return response()->json(['attachments' => $created], 201);
    }

    /** DELETE /api/v1/admin/todos/{id}/attachments/{attId} */
    public function destroyAttachment(string $id, int $attId)
    {
        $att = TodoAttachment::where('todo_id', $id)->where('id', $attId)->firstOrFail();
        Storage::disk('public')->delete($att->path);
        $att->delete();

        return response()->json(['ok' => true]);
    }

    // ── Deploy-app (X-Deploy-Token) — legacy queue-status ──────────────

    public function deployIndex()
    {
        $todos = Todo::query()->with(['labels', 'attachments'])->orderByDesc('created_at')->get();

        return response()->json([
            'todos' => $todos->map(fn ($t) => $t->toDeployArray())->values(),
        ]);
    }

    public function deployStore(Request $request)
    {
        $validated = $request->validate([
            'id'          => 'nullable|string|max:64',
            'title'       => 'required|string|max:5000',
            'description' => 'nullable|string|max:10000',
            'repo'        => 'nullable|string|max:50',
            'mode'        => 'nullable|string|max:50',
            'status'      => 'nullable|string|max:30',
        ]);

        $todo = Todo::create([
            'id'                => $validated['id'] ?? null,
            'title'             => $validated['title'],
            'description'       => $validated['description'] ?? '',
            'repo'              => $validated['repo'] ?? 'frontend',
            'mode'              => $validated['mode'] ?? 'fix',
            'status'            => $this->normalizeStatus($validated['status'] ?? null, 'backlog'),
            'source'            => 'deploy',
            'status_changed_at' => now(),
        ]);

        return response()->json(['todo' => $todo->toDeployArray()], 201);
    }

    public function deployUpdate(Request $request, string $id)
    {
        $todo = Todo::findOrFail($id);

        $validated = $request->validate([
            'title'            => 'sometimes|string|max:5000',
            'description'      => 'sometimes|nullable|string|max:10000',
            'repo'             => 'sometimes|nullable|string|max:50',
            'mode'             => 'sometimes|nullable|string|max:50',
            'status'           => 'sometimes|string|max:30',
            'lastExit'         => 'sometimes|nullable|integer',
            'followups'        => 'sometimes|nullable|array',
            'followups.*.text' => 'required_with:followups|string',
            'followups.*.at'   => 'nullable|string',
            'completedAt'      => 'sometimes|nullable|date',
            'statusChangedAt'  => 'sometimes|nullable|date',
        ]);

        foreach (['title', 'description', 'repo', 'mode'] as $k) {
            if (array_key_exists($k, $validated)) $todo->{$k} = $validated[$k];
        }
        if (array_key_exists('status', $validated)) {
            $todo->status = $this->normalizeStatus($validated['status'], $todo->status);
        }
        if (array_key_exists('lastExit', $validated))  $todo->last_exit = $validated['lastExit'];
        if (array_key_exists('followups', $validated)) $todo->followups = $validated['followups'];

        if (array_key_exists('statusChangedAt', $validated)) {
            $todo->status_changed_at = $validated['statusChangedAt'];
        } elseif ($todo->isDirty('status')) {
            $todo->status_changed_at = now();
        }
        if (array_key_exists('completedAt', $validated)) {
            $todo->completed_at = $validated['completedAt'];
        } elseif ($todo->isDirty('status')) {
            $todo->completed_at = $todo->status === 'productie' ? now() : null;
        }

        $todo->save();

        return response()->json(['todo' => $todo->toDeployArray()]);
    }

    public function deployDestroy(string $id)
    {
        Todo::where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** Gedeelde validatie voor admin store/update. $required = titel verplicht. */
    private function validateTask(Request $request, bool $required): array
    {
        $req = $required ? 'required' : 'sometimes';

        return $request->validate([
            'title'        => "$req|string|max:5000",
            'description'  => 'sometimes|nullable|string|max:100000',
            'repo'         => 'sometimes|nullable|string|max:50',
            'mode'         => 'sometimes|nullable|string|max:50',
            'status'       => ['sometimes', Rule::in(Todo::STATUSES)],
            'priority'     => ['sometimes', Rule::in(Todo::PRIORITIES)],
            'assignee'     => 'sometimes|nullable|string|max:60',
            'appVersion'   => 'sometimes|nullable|string|max:12',
            'deadlineWeek' => 'sometimes|nullable|integer|min:1|max:53',
            'deadlineYear' => 'sometimes|nullable|integer|min:2020|max:2100',
            'position'     => 'sometimes|integer',
            'labels'       => 'sometimes|nullable|array',
            'labels.*'     => 'integer|exists:task_labels,id',
        ]);
    }

    /** Accepteer zowel legacy queue-status als een workflow-fase. */
    private function normalizeStatus(?string $status, string $fallback): string
    {
        if (! $status) return $fallback;
        if (in_array($status, Todo::STATUSES, true)) return $status;
        return Todo::LEGACY_TO_STATUS[$status] ?? $fallback;
    }

    /** Aantallen per status, voor de kolom-tellers/badges. */
    private function statusCounts(): array
    {
        $counts = Todo::selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $out = ['all' => (int) $counts->sum()];
        foreach (Todo::STATUSES as $s) {
            $out[$s] = (int) ($counts[$s] ?? 0);
        }

        return $out;
    }
}
