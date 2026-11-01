<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MapWaypoint extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'map_id', 'user_id', 'local_id',
        'lon', 'lat', 'mgrs', 'label', 'color', 'icon', 'type', 'note', 'props',
        'tasks', 'task_radius',
    ];

    protected $casts = [
        'lon'         => 'float',
        'lat'         => 'float',
        'local_id'    => 'integer',
        'props'       => 'array',
        'tasks'       => 'array',
        'task_radius' => 'integer',
    ];

    /** Straal (m) waarbinnen "aankomst" telt als er geen eigen waarde is ingesteld. */
    public const DEFAULT_TASK_RADIUS = 50;

    public function map()
    {
        return $this->belongsTo(Map::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function images()
    {
        return $this->hasMany(MapWaypointImage::class, 'map_waypoint_id')->orderBy('created_at');
    }

    /** Wijzigingsgeschiedenis (nieuwste eerst). */
    public function revisions()
    {
        return $this->hasMany(MapWaypointRevision::class)->orderBy('id', 'desc');
    }

    public function toClientArray(): array
    {
        return [
            'id'       => $this->local_id,   // client uses local_id as its id
            'db_id'    => $this->id,
            'user_id'  => $this->user_id,
            'lon'      => (float) $this->lon,
            'lat'      => (float) $this->lat,
            'mgrs'     => $this->mgrs,
            'label'    => $this->label,
            'color'    => $this->color,
            'icon'     => $this->icon,
            'type'     => $this->type,
            'note'     => $this->note,
            'props'    => $this->props ?: (object) [],
            'tasks'       => array_values($this->tasks ?: []),
            'task_radius' => $this->task_radius,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'images'   => $this->relationLoaded('images')
                ? $this->images->map->toApiArray()->values()->all()
                : [],
        ];
    }
}
