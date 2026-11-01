<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Een team dat aan een missie hangt. Zie migratie create_mission_teams_table.
 */
class MissionTeam extends Model
{
    use HasUuids;

    protected $table = 'mission_teams';

    protected $fillable = [
        'mission_id',
        'team_id',
        'map_id',
        'layer_share',
        'sort_order',
    ];

    protected $casts = [
        'layer_share' => 'array',
        'sort_order'  => 'integer',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class, 'mission_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /** Team-id's die deze laag mogen zien (alleen bestaande, als strings). */
    public function sharedWith(): array
    {
        return array_values(array_unique(array_map('strval', array_filter((array) $this->layer_share))));
    }
}
