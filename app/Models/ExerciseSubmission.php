<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Actie (Submission) — claim van een deelnemer op een doel, door een controleur
 * goed- of afgekeurd.
 */
class ExerciseSubmission extends Model
{
    use HasUuids;

    protected $table = 'exercise_submissions';

    protected $fillable = [
        'objective_id',
        'mission_id',
        'user_id',
        'faction_id',
        'status',
        'note',
        'photo_path',
        'controller_id',
        'awarded_points',
        'submitted_at',
        'reviewed_at',
    ];

    protected $casts = [
        'awarded_points' => 'integer',
        'submitted_at'   => 'datetime',
        'reviewed_at'    => 'datetime',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(ExerciseObjective::class, 'objective_id');
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class, 'mission_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function faction(): BelongsTo
    {
        return $this->belongsTo(ExerciseFaction::class, 'faction_id');
    }

    public function controller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'controller_id');
    }
}
