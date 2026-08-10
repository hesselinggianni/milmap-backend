<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionBriefing extends Model
{
    protected $table = 'mission_briefings';

    protected $fillable = [
        'mission_id',
        'enemy_forces',
        'friendly_forces',
        'civilian_considerations',
        'ground_conditions',
        'commander_intent',
        'action_on_procedures',
        'timeline',
        'casevac',
        'medevac',
        'pace_plan',
        'weather',
        'light_conditions',
    ];

    // Het feitelijke bevel: vijand, eigen troepen, oogmerk, casevac/medevac.
    // Versleuteld at rest. De json-kolommen zijn hiervoor naar LONGTEXT
    // omgezet — MySQL valideert json en weigert een versleutelde string.
    // Zie 2026_08_10_200000_encrypt_sensitive_operational_data.
    // Let op: hierdoor is full-text zoeken op briefinginhoud niet mogelijk.
    protected $casts = [
        'timeline'         => 'encrypted:array',
        'pace_plan'        => 'encrypted:array',
        'weather'          => 'encrypted:array',
        'light_conditions' => 'encrypted:array',

        'enemy_forces'            => 'encrypted',
        'friendly_forces'         => 'encrypted',
        'civilian_considerations' => 'encrypted',
        'ground_conditions'       => 'encrypted',
        'commander_intent'        => 'encrypted',
        'action_on_procedures'    => 'encrypted',
        'casevac'                 => 'encrypted',
        'medevac'                 => 'encrypted',
    ];

    public function mission()
    {
        return $this->belongsTo(Mission::class);
    }
}
