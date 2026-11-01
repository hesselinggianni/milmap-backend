<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteMapPhoto extends Model
{
    protected $fillable = ['route_map_id', 'url', 'filename'];

    public function routeMap(): BelongsTo
    {
        return $this->belongsTo(RouteMap::class);
    }
}
