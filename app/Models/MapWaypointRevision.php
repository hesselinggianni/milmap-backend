<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eén regel in de wijzigingsgeschiedenis van een waypoint.
 */
class MapWaypointRevision extends Model
{
    protected $fillable = ['map_waypoint_id', 'user_id', 'action', 'diff'];

    protected $casts = ['diff' => 'array'];

    public function waypoint(): BelongsTo
    {
        return $this->belongsTo(MapWaypoint::class, 'map_waypoint_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Zelfde vorm als ReportRevision::toApiArray(), zodat de app één tijdlijn heeft. */
    public function toApiArray(): array
    {
        return [
            'id'         => $this->id,
            'action'     => $this->action,
            'changes'    => $this->diff,
            'user'       => $this->user ? [
                'id'   => $this->user->id,
                'name' => $this->user->full_name,
            ] : null,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
