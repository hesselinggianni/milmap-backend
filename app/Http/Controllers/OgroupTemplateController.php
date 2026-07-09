<?php

namespace App\Http\Controllers;

use App\Models\OgroupTemplate;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD voor herbruikbare O-group templates (fase-builder), gekozen bij het
 * aanmaken van een missie.
 *
 * Eigendomsmodel: per gebruiker (owner_id). Optioneel team-breed gedeeld via
 * is_shared + team_id. Lezen: eigen templates + gedeelde templates van teams
 * waar de gebruiker lid/eigenaar van is. Schrijven/verwijderen: alleen de
 * eigenaar. Conventie gespiegeld op MissionTaskTemplateController (inline
 * scoping, hand-built present(), geen Policy/Resource).
 */
class OgroupTemplateController extends Controller
{
    public function index()
    {
        $uid = Auth::id();
        $teamIds = $this->userTeamIds($uid);

        $templates = OgroupTemplate::where(function ($q) use ($uid, $teamIds) {
            $q->where('owner_id', $uid)
              ->orWhere(function ($s) use ($teamIds) {
                  $s->where('is_shared', true)->whereIn('team_id', $teamIds);
              });
        })
            ->orderBy('name')
            ->get();

        return response()->json([
            'templates' => $templates->map(fn ($t) => $this->present($t, $uid)),
        ]);
    }

    public function store(Request $request)
    {
        $uid = Auth::id();
        $data = $this->validateData($request);
        $this->guardTeam($uid, $data);

        $template = OgroupTemplate::create([
            'owner_id'    => $uid,
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'definition'  => $data['definition'] ?? ['phases' => []],
            'is_shared'   => $data['is_shared'] ?? false,
            'team_id'     => ($data['is_shared'] ?? false) ? ($data['team_id'] ?? null) : null,
        ]);

        return response()->json(['template' => $this->present($template, $uid)], 201);
    }

    public function show(string $id)
    {
        $uid = Auth::id();
        $template = $this->findReadable($id, $uid);
        return response()->json(['template' => $this->present($template, $uid)]);
    }

    public function update(Request $request, string $id)
    {
        $uid = Auth::id();
        $template = OgroupTemplate::where('owner_id', $uid)->findOrFail($id);

        $data = $this->validateData($request, true);
        $this->guardTeam($uid, $data);

        // Als delen uit staat, koppel team los.
        if (array_key_exists('is_shared', $data) && ! $data['is_shared']) {
            $data['team_id'] = null;
        }

        $template->fill(
            collect($data)->only(['name', 'description', 'definition', 'is_shared', 'team_id'])->all()
        )->save();

        return response()->json(['template' => $this->present($template->fresh(), $uid)]);
    }

    public function destroy(string $id)
    {
        $uid = Auth::id();
        $template = OgroupTemplate::where('owner_id', $uid)->findOrFail($id);
        $template->delete();
        return response()->json(['ok' => true]);
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function validateData(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'name'              => ($isUpdate ? 'sometimes|required' : 'required') . '|string|max:120',
            'description'       => 'nullable|string|max:500',
            'definition'        => 'nullable|array',
            'definition.phases' => 'nullable|array',
            'is_shared'         => 'nullable|boolean',
            'team_id'           => 'nullable|uuid|exists:teams,id',
        ]);
    }

    /** Team-ids waar de gebruiker lid of eigenaar van is. */
    private function userTeamIds($uid): array
    {
        $member = TeamMember::where('user_id', $uid)->pluck('team_id');
        $owned  = Team::where('owner_id', $uid)->pluck('id');
        return $member->merge($owned)->unique()->values()->all();
    }

    /** Delen mag alleen naar een team waar de gebruiker bij hoort. */
    private function guardTeam($uid, array $data): void
    {
        if (! empty($data['is_shared']) && ! empty($data['team_id'])
            && ! in_array($data['team_id'], $this->userTeamIds($uid), true)) {
            abort(403, 'Je kunt alleen delen met een team waar je lid van bent.');
        }
    }

    private function findReadable(string $id, $uid): OgroupTemplate
    {
        $teamIds = $this->userTeamIds($uid);
        return OgroupTemplate::where('id', $id)
            ->where(function ($q) use ($uid, $teamIds) {
                $q->where('owner_id', $uid)
                  ->orWhere(function ($s) use ($teamIds) {
                      $s->where('is_shared', true)->whereIn('team_id', $teamIds);
                  });
            })
            ->firstOrFail();
    }

    protected function present(OgroupTemplate $t, $uid): array
    {
        $phases = is_array($t->definition['phases'] ?? null) ? $t->definition['phases'] : [];

        return [
            'id'          => $t->id,
            'name'        => $t->name,
            'description' => $t->description,
            'definition'  => $t->definition ?: ['phases' => []],
            'phase_count' => count($phases),
            'is_shared'   => (bool) $t->is_shared,
            'team_id'     => $t->team_id,
            'is_owner'    => (int) $t->owner_id === (int) $uid,
            'updated_at'  => optional($t->updated_at)->toIso8601String(),
        ];
    }
}
