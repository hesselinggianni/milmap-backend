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
        'parent_id',
        'phase_key',
        'assignee_user_id',
        'assigned_team_id',
        'title',
        'description',
        'status',
        'priority',
        'order_index',
        'due_at',
        'created_by',
    ];

    protected $casts = [
        'due_at' => 'datetime',
    ];

    public const STATUSES = ['todo', 'doing', 'done'];
    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    public function mission()
    {
        return $this->belongsTo(Mission::class);
    }

    /** Legacy single assignee — gevuld met de eerste van assignees() voor back-compat. */
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /** "1 of meerdere leden" — bron van waarheid voor individuele toewijzing. */
    public function assignees()
    {
        return $this->belongsToMany(User::class, 'mission_task_user', 'mission_task_id', 'user_id')
            ->withTimestamps();
    }

    public function team()
    {
        return $this->belongsTo(Team::class, 'assigned_team_id');
    }

    /** Ouder-taak (null = top-level). */
    public function parent()
    {
        return $this->belongsTo(MissionTask::class, 'parent_id');
    }

    /** Subtaken, in volgorde. */
    public function children()
    {
        return $this->hasMany(MissionTask::class, 'parent_id')->orderBy('order_index');
    }
}
