<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionTaskTeamProgress extends Model
{
    protected $table = 'mission_task_team_progress';
    protected $guarded = ['id'];
    protected $casts = ['comments' => 'array'];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
