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

    protected $casts = [
        'timeline'         => 'array',
        'pace_plan'        => 'array',
        'weather'          => 'array',
        'light_conditions' => 'array',
    ];

    public function mission()
    {
        return $this->belongsTo(Mission::class);
    }
}
