<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaypointFloorplan extends Model
{
    protected $fillable = [
        'map_waypoint_id', 'name', 'floor_index', 'width', 'height',
        'background_image_path', 'created_by',
    ];

    protected $casts = [
        'floor_index' => 'integer',
        'width'       => 'integer',
        'height'      => 'integer',
    ];

    public function waypoint()
    {
        return $this->belongsTo(MapWaypoint::class, 'map_waypoint_id');
    }

    public function elements()
    {
        return $this->hasMany(FloorplanElement::class, 'floorplan_id')->orderBy('z_index');
    }

    public function toClientArray(): array
    {
        return [
            'id'                     => $this->id,
            'map_waypoint_id'        => $this->map_waypoint_id,
            'name'                   => $this->name,
            'floor_index'            => $this->floor_index,
            'width'                  => $this->width,
            'height'                 => $this->height,
            'background_image_url'   => $this->background_image_path
                ? asset('storage/' . $this->background_image_path)
                : null,
            'created_by'             => $this->created_by,
            'elements'               => $this->relationLoaded('elements')
                ? $this->elements->map->toClientArray()->values()->all()
                : [],
        ];
    }
}
