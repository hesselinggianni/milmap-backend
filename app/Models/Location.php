<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $fillable = [
        'map_id',
        'user_id',
        'name',
        'description',
        'latitude',
        'longitude',
        'color',
        'icon',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    /**
     * Relatie naar Map
     */
    public function map()
    {
        return $this->belongsTo(Map::class);
    }

    /**
     * Relatie naar User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
