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

    /** Eén sub-veld uit een O-group-paragraaf (nieuw object-formaat). Null als leeg. */
    private function ogroupField($para, string $key): ?string
    {
        if (is_array($para) && isset($para[$key]) && is_string($para[$key])) {
            $v = trim($para[$key]);
            return $v !== '' ? $v : null;
        }
        return null;
    }

    /**
     * Bouw een gelabelde tekst uit geselecteerde sub-velden. Lege velden slaan
     * we over; niet-lege krijgen hun label op een eigen regel. $map = [[key,label], …].
     */
    private function ogroupLabeled($para, array $map): ?string
    {
        if (! is_array($para)) {
            return null;
        }
        $parts = [];
        foreach ($map as [$key, $label]) {
            $v = $this->ogroupField($para, $key);
            if ($v !== null) {
                $parts[] = $label !== '' ? ($label . ":\n" . $v) : $v;
            }
        }
        return count($parts) ? implode("\n\n", $parts) : null;
    }

    /** Heeft deze paragraaf het nieuwe sub-velden-formaat (bekende keys aanwezig)? */
    private function isStructuredPara($para, array $keys): bool
    {
        if (! is_array($para)) {
            return false;
        }
        foreach ($keys as $k) {
            if (array_key_exists($k, $para)) {
                return true;
            }
        }
        return false;
    }

    private function buildPresentation(Mission $m): array
    {
        $ogroup = $m->ogroup ?? [];

        // O-group-paragrafen (nieuw sub-velden-formaat). De briefing-tabel blijft
        // leidend waar aanwezig; anders vullen we de slide-velden uit de ogroup.
        $sit = $ogroup['situation'] ?? null;
        $mis = $ogroup['mission'] ?? null;
        $exe = $ogroup['execution'] ?? null;
        $log = $ogroup['logistics'] ?? null;
        $cs  = $ogroup['command_signals'] ?? null;

        $sitStructured = $this->isStructuredPara($sit, ['general', 'enemy', 'friendly', 'attachments', 'civil']);
        $misStructured = $this->isStructuredPara($mis, ['statement', 'purpose']);
        $exeStructured = $this->isStructuredPara($exe, ['intent', 'concept', 'main_effort', 'phases', 'tasks', 'coordinating']);
        $logStructured = $this->isStructuredPara($log, ['supply', 'medical', 'transport', 'equipment', 'personnel']);
        $csStructured  = $this->isStructuredPara($cs, ['command', 'signal', 'pace', 'recognition', 'emergency']);

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

            // Paragraph 1: Situation. Bij het nieuwe sub-velden-formaat mappen we
            // vijand/eigen/civiel naar hun eigen slide-vak i.p.v. alles in overview.
            'situation' => [
                'overview' => $sitStructured
                    ? $this->ogroupLabeled($sit, [['general', ''], ['attachments', 'Bijgevoegd / onttrokken']])
                    : $this->ogroupText($sit),
                'enemy_forces'            => $m->briefing?->enemy_forces            ?? ($sitStructured ? $this->ogroupField($sit, 'enemy') : null),
                'friendly_forces'         => $m->briefing?->friendly_forces         ?? ($sitStructured ? $this->ogroupField($sit, 'friendly') : null),
                'civilian_considerations' => $m->briefing?->civilian_considerations ?? ($sitStructured ? $this->ogroupField($sit, 'civil') : null),
                'ground_conditions'       => $m->briefing?->ground_conditions,
                'weather'                 => $m->briefing?->weather,
                'light_conditions'        => $m->briefing?->light_conditions,
            ],

            // Paragraph 2: Mission — statement + oogmerk (purpose) apart.
            'mission' => [
                'statement'        => $misStructured ? $this->ogroupField($mis, 'statement') : $this->ogroupText($mis),
                'commander_intent' => $m->briefing?->commander_intent ?? ($misStructured ? $this->ogroupField($mis, 'purpose') : null),
            ],

            // Paragraph 3: Execution — gelabelde deelplannen; coördinatie → action-on.
            'execution' => [
                'plan' => $exeStructured
                    ? $this->ogroupLabeled($exe, [
                        ['intent', 'Oogmerk commandant'],
                        ['concept', 'Concept van de operatie'],
                        ['main_effort', 'Zwaartepunt'],
                        ['phases', 'Fasering'],
                        ['tasks', 'Taken per eenheid'],
                    ])
                    : $this->ogroupText($exe),
                'action_on_procedures' => $m->briefing?->action_on_procedures ?? ($exeStructured ? $this->ogroupField($exe, 'coordinating') : null),
                'timeline'             => $m->briefing?->timeline ?? [],
            ],

            // Paragraph 4: CSS — logistiek gelabeld; medisch → MEDEVAC-kaart.
            'css' => [
                'logistics' => $logStructured
                    ? $this->ogroupLabeled($log, [
                        ['supply', 'Bevoorrading'],
                        ['transport', 'Transport & verplaatsing'],
                        ['equipment', 'Materieel & uitrusting'],
                        ['personnel', 'Personeel'],
                    ])
                    : $this->ogroupText($log),
                'casevac'   => $m->briefing?->casevac,
                'medevac'   => $m->briefing?->medevac ?? ($logStructured ? $this->ogroupField($log, 'medical') : null),
                'pace_plan' => $m->briefing?->pace_plan ?? [],
            ],

            // Paragraph 5: Command & Signals — gelabelde deelvelden in overview.
            'command_signals' => [
                'overview' => $csStructured
                    ? $this->ogroupLabeled($cs, [
                        ['command', 'Commandovoering'],
                        ['signal', 'Verbindingen'],
                        ['pace', 'PACE-plan'],
                        ['recognition', 'Herkenning & wachtwoorden'],
                        ['emergency', 'Noodprocedures'],
                    ])
                    : $this->ogroupText($cs),
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
