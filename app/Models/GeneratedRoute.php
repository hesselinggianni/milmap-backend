<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class GeneratedRoute extends Model
{
    use HasUuids;

    protected $table = 'generated_routes';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'route_map_id',
        'user_id',
        'options',
        'route_geojson',
        'total_distance_m',
        'total_elevation_m',
        'total_time_minutes',
        'status',
        'applied_at',
    ];

    // The `options` column is nullable (MySQL forbids a JSON default), so give
    // it an empty-object default here — it always reads back as an array.
    protected $attributes = [
        'options' => '{}',
    ];

    protected $casts = [
        'options' => 'array',
        'route_geojson' => 'array',
        'total_distance_m' => 'float',
        'total_elevation_m' => 'float',
        'total_time_minutes' => 'float',
        'applied_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function routeMap()
    {
        return $this->belongsTo(RouteMap::class, 'route_map_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeGenerated($query)
    {
        return $query->where('status', 'generated');
    }

    public function scopeApplied($query)
    {
        return $query->where('status', 'applied');
    }

    public function scopeForRouteMap($query, $routeMapId)
    {
        return $query->where('route_map_id', $routeMapId);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function apply()
    {
        $this->update([
            'status' => 'applied',
            'applied_at' => now(),
        ]);
    }

    public function getRouteCoordinates()
    {
        if (! isset($this->route_geojson['coordinates'])) {
            return [];
        }

        return array_map(function ($coord) {
            return [
                'lon' => $coord[0],
                'lat' => $coord[1],
            ];
        }, $this->route_geojson['coordinates']);
    }
}
