<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Partij (Faction) — een team binnen één oefening; deelnemers kiezen hierin.
 */
class ExerciseFaction extends Model
{
    use HasUuids;

    protected $table = 'exercise_factions';

    protected $fillable = [
        'mission_id',
        'name',
        'color',
        'spawn_lat',
        'spawn_lng',
    ];

    protected $casts = [
        'spawn_lat' => 'float',
        'spawn_lng' => 'float',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class, 'mission_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ExerciseParticipant::class, 'faction_id');
    }
}
