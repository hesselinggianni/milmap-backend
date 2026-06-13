<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Deploy-todo's: de gedeelde takenlijst van de deploy-app, nu opgeslagen in
 * Laravel. Twee toegangswegen op dezelfde tabel:
 *
 *   • admin*  — voor het MilMap-admin paneel (Sanctum + admin.auth). Een admin
 *               kan taken aanmaken, bijwerken en verwijderen.
 *   • deploy* — voor de headless deploy-app (X-Deploy-Token). Synchroniseert de
 *               volledige lijst en werkt status/exit-code/vervolgprompts bij.
 *
 * Beide kanten delen exact dezelfde JSON-shape (Todo::toApiArray, camelCase).
 */
class TodoController extends Controller
{
    // ── Admin (MilMap-paneel) ──────────────────────────────────────────

    /**
     * GET /api/v1/admin/todos — overzicht met optioneel statusfilter.
     */
    public function adminIndex(Request $request)
    {
        $request->validate([
            'q'      => 'nullable|string|max:255',
            'status' => ['nullable', Rule::in(Todo::STATUSES)],
        ]);

        $query = Todo::query()->with('creator');

        if ($qs = $request->query('q')) {
            $query->where(function ($w) use ($qs) {
                $w->where('title', 'like', '%' . $qs . '%')
                    ->orWhere('description', 'like', '%' . $qs . '%');
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $todos = $query->orderByDesc('created_at')->get();

        return response()->json([
            'todos'  => $todos->map(fn ($t) => $t->toApiArray())->values(),
            'counts' => $this->statusCounts(),
        ]);
    }

    /**
     * POST /api/v1/admin/todos — admin maakt een nieuwe taak.
     */
    public function adminStore(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:5000',
            'description' => 'nullable|string|max:10000',
            'repo'        => 'nullable|string|max:50',
            'mode'        => 'nullable|string|max:50',
        ]);

        $todo = Todo::create([
            'title'             => $validated['title'],
            'description'       => $validated['description'] ?? null,
            'repo'              => $validated['repo'] ?? 'frontend',
            'mode'              => $validated['mode'] ?? 'fix',
            'status'            => 'pending',
            'source'            => 'admin',
            'created_by'        => Auth::id(),
            'status_changed_at' => now(),
        ]);

        return response()->json(['todo' => $todo->fresh('creator')->toApiArray()], 201);
    }

    /**
     * PUT /api/v1/admin/todos/{id} — admin werkt een taak bij.
     */
    public function adminUpdate(Request $request, string $id)
    {
        $todo = Todo::findOrFail($id);

        $validated = $request->validate([
            'title'       => 'sometimes|string|max:5000',
            'description' => 'sometimes|nullable|string|max:10000',
            'repo'        => 'sometimes|nullable|string|max:50',
            'mode'        => 'sometimes|nullable|string|max:50',
            'status'      => ['sometimes', Rule::in(Todo::STATUSES)],
        ]);

        if (array_key_exists('status', $validated) && $validated['status'] !== $todo->status) {
            $todo->status_changed_at = now();
            $todo->completed_at = in_array($validated['status'], ['done', 'failed'], true)
                ? now()
                : null;
        }

        $todo->fill($validated)->save();

        return response()->json(['todo' => $todo->fresh('creator')->toApiArray()]);
    }

    /**
     * DELETE /api/v1/admin/todos/{id}
     */
    public function adminDestroy(string $id)
    {
        Todo::where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    // ── Deploy-app (X-Deploy-Token) ────────────────────────────────────

    /**
     * GET /api/v1/deploy/todos — volledige lijst voor de deploy-app.
     */
    public function deployIndex()
    {
        $todos = Todo::query()->orderByDesc('created_at')->get();

        return response()->json([
            'todos' => $todos->map(fn ($t) => $t->toApiArray())->values(),
        ]);
    }

    /**
     * POST /api/v1/deploy/todos — de deploy-app maakt (of her-upsert) een taak.
     * Aanvaardt het eigen client-id zodat queue/log-koppelingen blijven kloppen.
     */
    public function deployStore(Request $request)
    {
        $validated = $request->validate([
            'id'          => 'nullable|string|max:64',
            'title'       => 'required|string|max:5000',
            'description' => 'nullable|string|max:10000',
            'repo'        => 'nullable|string|max:50',
            'mode'        => 'nullable|string|max:50',
            'status'      => ['nullable', Rule::in(Todo::STATUSES)],
        ]);

        $todo = Todo::create([
            'id'                => $validated['id'] ?? null,
            'title'             => $validated['title'],
            'description'       => $validated['description'] ?? '',
            'repo'              => $validated['repo'] ?? 'frontend',
            'mode'              => $validated['mode'] ?? 'fix',
            'status'            => $validated['status'] ?? 'pending',
            'source'            => 'deploy',
            'status_changed_at' => now(),
        ]);

        return response()->json(['todo' => $todo->toApiArray()], 201);
    }

    /**
     * PUT /api/v1/deploy/todos/{id} — de deploy-app werkt status / exit-code /
     * vervolgprompts bij. De deploy-app is hier autoritair over de timing, dus
     * meegestuurde tijdstempels winnen; anders beheren we ze automatisch.
     */
    public function deployUpdate(Request $request, string $id)
    {
        $todo = Todo::findOrFail($id);

        $validated = $request->validate([
            'title'           => 'sometimes|string|max:5000',
            'description'     => 'sometimes|nullable|string|max:10000',
            'repo'            => 'sometimes|nullable|string|max:50',
            'mode'            => 'sometimes|nullable|string|max:50',
            'status'         => ['sometimes', Rule::in(Todo::STATUSES)],
            'lastExit'        => 'sometimes|nullable|integer',
            'followups'       => 'sometimes|nullable|array',
            'followups.*.text' => 'required_with:followups|string',
            'followups.*.at'  => 'nullable|string',
            'completedAt'     => 'sometimes|nullable|date',
            'statusChangedAt' => 'sometimes|nullable|date',
        ]);

        // camelCase payload → snake_case kolommen.
        foreach (['title', 'description', 'repo', 'mode', 'status'] as $k) {
            if (array_key_exists($k, $validated)) {
                $todo->{$k} = $validated[$k];
            }
        }
        if (array_key_exists('lastExit', $validated))  $todo->last_exit = $validated['lastExit'];
        if (array_key_exists('followups', $validated)) $todo->followups = $validated['followups'];

        if (array_key_exists('statusChangedAt', $validated)) {
            $todo->status_changed_at = $validated['statusChangedAt'];
        } elseif (array_key_exists('status', $validated) && $todo->isDirty('status')) {
            $todo->status_changed_at = now();
        }

        if (array_key_exists('completedAt', $validated)) {
            $todo->completed_at = $validated['completedAt'];
        } elseif (array_key_exists('status', $validated) && $todo->isDirty('status')) {
            $todo->completed_at = in_array($validated['status'], ['done', 'failed'], true)
                ? now()
                : null;
        }

        $todo->save();

        return response()->json(['todo' => $todo->toApiArray()]);
    }

    /**
     * DELETE /api/v1/deploy/todos/{id}
     */
    public function deployDestroy(string $id)
    {
        Todo::where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** Aantallen per status, voor de filter-badges in het admin-panel. */
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
