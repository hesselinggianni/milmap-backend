<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MapWaypoint extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'map_id', 'user_id', 'local_id',
        'lon', 'lat', 'mgrs', 'label', 'color', 'icon',
    ];

    protected $casts = [
        'lon'      => 'float',
        'lat'      => 'float',
        'local_id' => 'integer',
    ];

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
            'images'   => $this->relationLoaded('images')
                ? $this->images->map->toApiArray()->values()->all()
                : [],
        ];
    }
}
