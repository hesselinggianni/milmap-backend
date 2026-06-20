<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class RouteMap extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'route_maps';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'map_id',
        'mission_id',
        'owner_id',
        'title',
        'date',
        'time',
        'color',
        'declination',
        'equipment',
        'speed',
        'ic',
        'cs',
        'locations',

        'pause_time',
        'total_time',
        'total_distance',
        'total_elevation',

        'meta',

        'bijlage',
        'callsign',
        'eenheid',
        'sterkte',
        'kaartblad',
        'vtv_vta',
    ];

    protected $casts = [
        'locations' => 'array',
        'date' => 'date:Y-m-d',

        'meta' => 'array',
        'bijlage' => 'array',
        'sterkte' => 'integer',

        'declination' => 'float',

        'pause_time' => 'integer',
        'total_time' => 'integer',
    
        'total_distance' => 'float',
        'total_elevation' => 'float',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function map()
    {
        return $this->belongsTo(Map::class, 'map_id');
    }

    public function mission()
    {
        return $this->belongsTo(Mission::class, 'mission_id');
    }

    public function generatedRoutes()
    {
        return $this->hasMany(GeneratedRoute::class, 'route_map_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function getCheckpointCountAttribute(): int
    {
        return collect($this->locations)
            ->filter(fn ($loc) =>
                data_get($loc, 'checkpoint.enabled') === true
            )
            ->count();
    }
}