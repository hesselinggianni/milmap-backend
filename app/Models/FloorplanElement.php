<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FloorplanElement extends Model
{
    protected $fillable = [
        'floorplan_id', 'type', 'geometry', 'style', 'label', 'z_index', 'created_by',
    ];

    protected $casts = [
        'geometry' => 'array',
        'style'    => 'array',
        'z_index'  => 'integer',
    ];

    public function floorplan()
    {
        return $this->belongsTo(WaypointFloorplan::class, 'floorplan_id');
    }

    public function toClientArray(): array
    {
        return [
            'id'            => $this->id,
            'floorplan_id'  => $this->floorplan_id,
            'type'          => $this->type,
            'geometry'      => $this->geometry ?? [],
            'style'         => $this->style ?? [],
            'label'         => $this->label,
            'z_index'       => $this->z_index,
            'created_by'    => $this->created_by,
        ];
    }
}
