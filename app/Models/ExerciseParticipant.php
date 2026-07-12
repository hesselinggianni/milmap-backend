<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deelnemer-in-een-oefening: partijkeuze, regels-akkoord en per-oefening
 * rol-override in één record.
 */
class ExerciseParticipant extends Model
{
    use HasUuids;

    protected $table = 'exercise_participants';

    protected $fillable = [
        'mission_id',
        'user_id',
        'faction_id',
        'role_override',
        'accepted_rules_version',
        'accepted_at',
        'status',
        'joined_at',
    ];

    protected $casts = [
        'accepted_rules_version' => 'integer',
        'accepted_at'            => 'datetime',
        'joined_at'              => 'datetime',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::uuid();
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

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
