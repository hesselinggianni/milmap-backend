<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MissionEvaluation extends Model
{
    use HasUuids;

    protected $table = 'mission_evaluations';

    protected $fillable = [
        'mission_id',
        'user_id',
        'rating',
        'goal_achieved',
        'went_well',
        'went_wrong',
        'notes',
        'submitted_at',
    ];

    protected $casts = [
        'rating'       => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function mission()
    {
        return $this->belongsTo(Mission::class, 'mission_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
