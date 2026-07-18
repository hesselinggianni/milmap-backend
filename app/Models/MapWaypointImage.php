<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Een foto die bij een waypoint-locatie hoort. Opgeslagen op de 'public' disk
 * onder waypoints/{waypointId}/... en publiek te tonen via url().
 */
class MapWaypointImage extends Model
{
    protected $fillable = ['map_waypoint_id', 'user_id', 'path', 'original_name', 'mime', 'size'];

    protected $casts = ['size' => 'integer'];

    public function waypoint(): BelongsTo
    {
        return $this->belongsTo(MapWaypoint::class, 'map_waypoint_id');
    }

    public function url(): string
    {
        try {
            return Storage::disk('public')->url($this->path);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function toApiArray(): array
    {
        return [
            'id'   => $this->id,
            'url'  => $this->url(),
            'mime' => $this->mime,
            'size' => $this->size,
        ];
    }
}
