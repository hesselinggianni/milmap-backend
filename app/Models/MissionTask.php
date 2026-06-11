<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MissionTask extends Model
{
    use HasUuids;

    protected $table = 'mission_tasks';

    protected $fillable = [
        'mission_id',
        'assignee_user_id',
        'assigned_team_id',
        'title',
        'description',
        'status',
        'order_index',
        'due_at',
        'created_by',
    ];

    protected $casts = [
        'due_at' => 'datetime',
    ];

    public const STATUSES = ['todo', 'doing', 'done'];

    public function mission()
    {
        return $this->belongsTo(Mission::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class, 'assigned_team_id');
    }
}
