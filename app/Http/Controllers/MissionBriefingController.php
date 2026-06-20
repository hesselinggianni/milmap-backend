<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Models\MissionBriefing;
use App\Models\MissionRadioChannel;
use App\Models\MissionRisk;
use App\Models\MissionAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MissionBriefingController extends Controller
{
    /**
     * GET /api/v1/missions/{id}/presentation
     * Full payload for the 8-slide presentation.
     */
    public function presentation($id)
    {
        $mission = Mission::with([
            'briefing',
            'radioChannels',
            'risks',
            'routeMaps',
            'linkedTeam',
            'collaborators.user',
        ])->findOrFail($id);

        $userId = Auth::id();
        if (! $mission->hasAccess($userId)) {
            abort(403, 'No access');
        }

        return response()->json(['data' => $this->buildPresentation($mission)]);
    }

    /**
     * GET /api/v1/missions/{id}/briefing
     */
    public function show($id)
    {
        $mission = Mission::findOrFail($id);
        if (! $mission->hasAccess(Auth::id())) abort(403);

        return response()->json([
            'data' => $mission->briefing ?? new MissionBriefing(['mission_id' => $id]),
        ]);
    }

    /**
     * PUT /api/v1/missions/{id}/briefing
     */
    public function update(Request $request, $id)
    {
        $mission = Mission::findOrFail($id);
        $role = $mission->roleFor(Auth::id());
        if (! in_array($role, ['owner', 'editor', 'admin'])) abort(403);
        if ($mission->locked) abort(423, 'Mission is locked');

        $data = $request->validate([
            'enemy_forces'            => 'nullable|string',
            'friendly_forces'         => 'nullable|string',
            'civilian_considerations' => 'nullable|string',
            'ground_conditions'       => 'nullable|string',
            'commander_intent'        => 'nullable|string',
            'action_on_procedures'    => 'nullable|string',
            'timeline'                => 'nullable|array',
            'casevac'                 => 'nullable|string',
            'medevac'                 => 'nullable|string',
            'pace_plan'               => 'nullable|array',
            'weather'                 => 'nullable|array',
            'light_conditions'        => 'nullable|array',
        ]);

        $briefing = MissionBriefing::updateOrCreate(
            ['mission_id' => $id],
            $data
        );

        $this->log($id, 'briefing_updated', ['fields' => array_keys($data)]);

        return response()->json(['data' => $briefing]);
    }

    /**
     * GET /api/v1/missions/{id}/radio-channels
     */
    public function listChannels($id)
    {
        $mission = Mission::findOrFail($id);
        if (! $mission->hasAccess(Auth::id())) abort(403);

        return response()->json(['data' => $mission->radioChannels]);
    }

    /**
     * POST /api/v1/missions/{id}/radio-channels
     */
    public function storeChannel(Request $request, $id)
    {
        $mission = Mission::findOrFail($id);
        if (! in_array($mission->roleFor(Auth::id()), ['owner', 'editor', 'admin'])) abort(403);
        if ($mission->locked) abort(423, 'Mission is locked');

        $data = $request->validate([
            'net_name'   => 'required|string|max:128',
            'frequency'  => 'nullable|string|max:32',
            'callsign'   => 'nullable|string|max:64',
            'encryption' => 'nullable|string|max:64',
            'mode'       => 'nullable|string|max:32',
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $channel = MissionRadioChannel::create(array_merge($data, ['mission_id' => $id]));
        $this->log($id, 'radio_channel_added', ['net_name' => $channel->net_name]);

        return response()->json(['data' => $channel], 201);
    }

    /**
     * PUT /api/v1/missions/{id}/radio-channels/{channelId}
     */
    public function updateChannel(Request $request, $id, $channelId)
    {
        $mission = Mission::findOrFail($id);
        if (! in_array($mission->roleFor(Auth::id()), ['owner', 'editor', 'admin'])) abort(403);
        if ($mission->locked) abort(423, 'Mission is locked');

        $channel = MissionRadioChannel::where('mission_id', $id)->findOrFail($channelId);
        $channel->update($request->validate([
            'net_name'   => 'string|max:128',
            'frequency'  => 'nullable|string|max:32',
            'callsign'   => 'nullable|string|max:64',
            'encryption' => 'nullable|string|max:64',
            'mode'       => 'nullable|string|max:32',
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ]));

        return response()->json(['data' => $channel]);
    }

    /**
     * DELETE /api/v1/missions/{id}/radio-channels/{channelId}
     */
    public function destroyChannel($id, $channelId)
    {
        $mission = Mission::findOrFail($id);
        if (! in_array($mission->roleFor(Auth::id()), ['owner', 'editor', 'admin'])) abort(403);
        if ($mission->locked) abort(423, 'Mission is locked');

        MissionRadioChannel::where('mission_id', $id)->findOrFail($channelId)->delete();
        return response()->noContent();
    }

    /**
     * GET /api/v1/missions/{id}/risks
     */
    public function listRisks($id)
    {
        $mission = Mission::findOrFail($id);
        if (! $mission->hasAccess(Auth::id())) abort(403);

        return response()->json(['data' => $mission->risks->append(['risk_score', 'level'])]);
    }

    /**
     * POST /api/v1/missions/{id}/risks
     */
    public function storeRisk(Request $request, $id)
    {
        $mission = Mission::findOrFail($id);
        if (! in_array($mission->roleFor(Auth::id()), ['owner', 'editor', 'admin'])) abort(403);
        if ($mission->locked) abort(423, 'Mission is locked');

        $data = $request->validate([
            'description' => 'required|string|max:512',
            'likelihood'  => 'integer|min:1|max:5',
            'impact'      => 'integer|min:1|max:5',
            'mitigation'  => 'nullable|string',
            'sort_order'  => 'integer',
        ]);

        $risk = MissionRisk::create(array_merge($data, ['mission_id' => $id]));
        $this->log($id, 'risk_added', ['description' => $risk->description]);

        return response()->json(['data' => $risk->append(['risk_score', 'level'])], 201);
    }

    /**
     * PUT /api/v1/missions/{id}/risks/{riskId}
     */
    public function updateRisk(Request $request, $id, $riskId)
    {
        $mission = Mission::findOrFail($id);
        if (! in_array($mission->roleFor(Auth::id()), ['owner', 'editor', 'admin'])) abort(403);
        if ($mission->locked) abort(423, 'Mission is locked');

        $risk = MissionRisk::where('mission_id', $id)->findOrFail($riskId);
        $risk->update($request->validate([
            'description' => 'string|max:512',
            'likelihood'  => 'integer|min:1|max:5',
            'impact'      => 'integer|min:1|max:5',
            'mitigation'  => 'nullable|string',
            'sort_order'  => 'integer',
        ]));

        return response()->json(['data' => $risk->append(['risk_score', 'level'])]);
    }

    /**
     * DELETE /api/v1/missions/{id}/risks/{riskId}
     */
    public function destroyRisk($id, $riskId)
    {
        $mission = Mission::findOrFail($id);
        if (! in_array($mission->roleFor(Auth::id()), ['owner', 'editor', 'admin'])) abort(403);
        if ($mission->locked) abort(423, 'Mission is locked');

        MissionRisk::where('mission_id', $id)->findOrFail($riskId)->delete();
        return response()->noContent();
    }

    /**
     * POST /api/v1/missions/{id}/approve
     */
    public function approve($id)
    {
        $mission = Mission::findOrFail($id);
        $role = $mission->roleFor(Auth::id());
        if (! in_array($role, ['owner', 'admin'])) abort(403);

        $mission->update([
            'status'      => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'locked'      => true,
        ]);

        $this->log($id, 'approved', ['approved_by' => Auth::id()]);
        return response()->json(['data' => ['status' => 'approved', 'locked' => true]]);
    }

    /**
     * POST /api/v1/missions/{id}/unlock
     */
    public function unlock($id)
    {
        $mission = Mission::findOrFail($id);
        if (! in_array($mission->roleFor(Auth::id()), ['owner', 'admin'])) abort(403);

        $mission->update(['locked' => false, 'status' => 'planning']);
        $this->log($id, 'unlocked');
        return response()->json(['data' => ['locked' => false]]);
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private function log(string $missionId, string $action, array $payload = []): void
    {
        MissionAuditLog::create([
            'mission_id' => $missionId,
            'user_id'    => Auth::id(),
            'action'     => $action,
            'payload'    => $payload ?: null,
        ]);
    }

    /**
     * Vlak een O-group-paragraaf af tot tekst voor de presentatie. De editor
     * bewaart per paragraaf een object van sub-velden; oudere missies bewaarden
     * één losse string. Beide → één leesbare tekst (sub-velden op aparte regels).
     */
    private function ogroupText($value): ?string
    {
        if (is_string($value)) {
            return trim($value) !== '' ? $value : null;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $v) {
                if (is_string($v) && trim($v) !== '') {
                    $parts[] = trim($v);
                }
            }
            return count($parts) ? implode("\n", $parts) : null;
        }
        return null;
    }

    private function buildPresentation(Mission $m): array
    {
        $ogroup = $m->ogroup ?? [];

        return [
            // Mission meta
            'id'              => $m->id,
            'name'            => $m->name,
            'mission_number'  => $m->mission_number,
            'classification'  => $m->classification,
            'status'          => $m->status,
            'locked'          => $m->locked,
            'approved_at'     => $m->approved_at?->toIso8601String(),
            'date'            => $m->date?->toDateString(),
            'time'            => $m->time,
            'logo'            => $m->logo,
            'description'     => $m->description,

            // Paragraph 1: Situation (briefing table + ogroup fallback)
            'situation' => [
                'overview'                => $this->ogroupText($ogroup['situation'] ?? null),
                'enemy_forces'            => $m->briefing?->enemy_forces,
                'friendly_forces'         => $m->briefing?->friendly_forces,
                'civilian_considerations' => $m->briefing?->civilian_considerations,
                'ground_conditions'       => $m->briefing?->ground_conditions,
                'weather'                 => $m->briefing?->weather,
                'light_conditions'        => $m->briefing?->light_conditions,
            ],

            // Paragraph 2: Mission
            'mission' => [
                'statement'        => $this->ogroupText($ogroup['mission'] ?? null),
                'commander_intent' => $m->briefing?->commander_intent,
            ],

            // Paragraph 3: Execution
            'execution' => [
                'plan'                 => $this->ogroupText($ogroup['execution'] ?? null),
                'action_on_procedures' => $m->briefing?->action_on_procedures,
                'timeline'             => $m->briefing?->timeline ?? [],
            ],

            // Paragraph 4: CSS (Combat Service Support)
            'css' => [
                'logistics' => $this->ogroupText($ogroup['logistics'] ?? null),
                'casevac'   => $m->briefing?->casevac,
                'medevac'   => $m->briefing?->medevac,
                'pace_plan' => $m->briefing?->pace_plan ?? [],
            ],

            // Paragraph 5: Command & Signals
            'command_signals' => [
                'overview'       => $this->ogroupText($ogroup['command_signals'] ?? null),
                'radio_channels' => $m->radioChannels->toArray(),
            ],

            // Supporting data
            'risks'      => $m->risks->append(['risk_score', 'level'])->toArray(),
            'route_maps' => $m->routeMaps->map(fn ($r) => [
                'id'             => $r->id,
                'title'          => $r->title,
                'color'          => $r->color,
                'callsign'       => $r->callsign,
                'eenheid'        => $r->eenheid,
                'sterkte'        => $r->sterkte,
                'total_distance' => $r->total_distance,
                'total_time'     => $r->total_time,
                'locations'      => $r->locations ?? [],
            ])->toArray(),
            'participants'    => $ogroup['participants'] ?? [],
            'warning_order'   => $ogroup['warning_order'] ?? null,
            'rv'              => $ogroup['RV'] ?? null,
            'team'            => $m->linkedTeam ? [
                'id'   => $m->linkedTeam->id,
                'name' => $m->linkedTeam->name,
            ] : null,
        ];
    }
}
