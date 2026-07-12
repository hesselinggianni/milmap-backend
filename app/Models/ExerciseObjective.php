<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Doel (Objective) — taak met locatie- en/of tijd-eis; zie docs/oefening-modus.md.
 */
class ExerciseObjective extends Model
{
    use HasUuids;

    protected $table = 'exercise_objectives';

    protected $fillable = [
        'mission_id',
        'faction_id',
        'title',
        'description',
        'type',
        'target_lat',
        'target_lng',
        'radius_m',
        'hold_seconds',
        'window_start',
        'window_end',
        'points',
        'verification',
        'status',
    ];

    protected $casts = [
        'target_lat'   => 'float',
        'target_lng'   => 'float',
        'radius_m'     => 'integer',
        'hold_seconds' => 'integer',
        'window_start' => 'datetime',
        'window_end'   => 'datetime',
        'points'       => 'integer',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class, 'mission_id');
    }

    public function faction(): BelongsTo
    {
        return $this->belongsTo(ExerciseFaction::class, 'faction_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ExerciseSubmission::class, 'objective_id');
    }
}
