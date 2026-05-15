<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RouteMap extends Model
{
    use HasUuids;

    protected $table = 'route_maps';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'map_id',
        'owner_id',
        'title',
        'date',
        'time',
        'color',
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
    ];

    protected $casts = [
        'locations' => 'array',
        'date' => 'date:Y-m-d',

        'meta' => 'array',
           

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