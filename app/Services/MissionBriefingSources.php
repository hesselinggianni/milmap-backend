<?php
namespace App\Services;
use App\Models\Mission;
use App\Models\MissionTask;

/** Adapters for the actual SMEAC, custom-phase and supporting mission models. */
class MissionBriefingSources {
    public function forMission(Mission $m): array {
        $m->loadMissing(['briefing', 'radioChannels', 'routeMaps', 'risks']);
        $og = $m->ogroup ?? [];
        $values = [
            'situation' => ['Situatie', $og['situation'] ?? null],
            'mission' => ['Opdracht', $og['mission'] ?? $m->description],
            'execution' => ['Uitvoering', $og['execution'] ?? null],
            'logistics' => ['Logistiek', $og['logistics'] ?? null],
            'medical' => ['Medisch', ['casevac' => $m->briefing?->casevac, 'medevac' => $m->briefing?->medevac ?? (is_array($og['logistics'] ?? null) ? ($og['logistics']['medical'] ?? null) : null)]],
            'communication' => ['Radio en communicatie', ['command_signals' => $og['command_signals'] ?? null, 'channels' => $m->radioChannels->toArray()]],
            'team' => ['Team en taakverdeling', ['participants' => $og['participants'] ?? [], 'tasks' => MissionTask::where('mission_id', $m->id)->with(['assignees:id,first_name,last_name', 'team:id,name'])->orderBy('order_index')->get()->toArray()]],
            'waypoints' => ['Waypoints en locaties', $m->routeMaps->flatMap(fn ($r) => $r->locations ?? [])->values()->all()],
            'planning' => ['Planning en agenda', $og['timeline'] ?? $m->briefing?->timeline ?? []],
            'weather' => ['Weer en daglicht', ['weather' => $m->briefing?->weather, 'light_conditions' => $m->briefing?->light_conditions]],
            'routes' => ['Routes', $m->routeMaps->map(fn ($r) => $r->only(['id', 'title', 'color', 'locations', 'total_distance', 'total_time']))->all()],
            'risks' => ['Risico’s', $m->risks->append(['risk_score', 'level'])->toArray()],
            'rv' => ['Verzamelpunt', $og['rv'] ?? $og['RV'] ?? null],
            'warning_order' => ['Voorbericht', $og['warning_order'] ?? null],
        ];
        foreach (['mission' => ['commander_intent'], 'execution' => ['action_on_procedures'], 'logistics' => ['pace_plan']] as $group => $fields) {
            foreach ($fields as $field) if ($m->briefing?->$field) {
                if (!is_array($values[$group][1])) $values[$group][1] = ['overview' => $values[$group][1]];
                $values[$group][1][$field] = $m->briefing->$field;
            }
        }
        // Legacy briefing fields take precedence just as in the existing presentation.
        foreach (['enemy_forces', 'friendly_forces', 'civilian_considerations', 'ground_conditions'] as $key) {
            if ($m->briefing?->$key) {
                if (!is_array($values['situation'][1])) $values['situation'][1] = ['overview' => $values['situation'][1]];
                $values['situation'][1][$key] = $m->briefing->$key;
            }
        }
        foreach ($og['template']['phases'] ?? [] as $phase) {
            $key = $phase['key'];
            $pv = $og['values'][$key] ?? [];
            $fields = [];
            foreach ($phase['fields'] ?? [] as $f) $fields[$f['label'] ?? $f['key']] = $pv['fields'][$f['key']] ?? null;
            foreach ($phase['subphases'] ?? [] as $sub) {
                $sv = [];
                foreach ($sub['fields'] ?? [] as $f) $sv[$f['label'] ?? $f['key']] = $pv['subphases'][$sub['key']]['fields'][$f['key']] ?? null;
                $fields[$sub['label'] ?? $sub['key']] = $sv;
            }
            $values['phase:'.$key] = [$phase['label'] ?? $key, $fields];
        }
        foreach ($og['extra_fields'] ?? [] as $field) $values['extra:'.$field['id']] = [$field['label'] ?? 'Extra veld', $field];
        $out = [];
        foreach ($values as $key => [$label, $value]) {
            $value = $this->plain($value);
            $out[] = ['key' => $key, 'label' => strip_tags($label), 'value' => $value, 'hash' => hash('sha256', json_encode($value))];
        }
        return $out;
    }
    public function plain($value, int $depth = 0) {
        if ($depth > 12) return null;
        if (is_string($value)) return html_entity_decode(strip_tags(preg_replace('/<\s*(br|\/p|\/div)\b[^>]*>/i', "\n", $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!is_array($value)) return is_scalar($value) || $value === null ? $value : null;
        $out = [];
        foreach ($value as $key => $v) $out[$key] = $this->plain($v, $depth + 1);
        return $out;
    }
}
