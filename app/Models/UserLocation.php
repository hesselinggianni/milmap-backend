<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLocation extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'map_id',
        'user_id',
        'latitude',
        'longitude',
        'accuracy',
        'heading',
        'speed',
        'device_id',
        'last_updated_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy' => 'float',
        'heading' => 'float',
        'speed' => 'float',
        'last_updated_at' => 'datetime',
    ];

    /**
     * Get the map this location belongs to.
     */
    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class);
    }

    /**
     * Get the user who shared this location.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if location is still fresh (updated within 2 minutes).
     */
    public function isFresh(): bool
    {
        return $this->last_updated_at->isAfter(now()->subMinutes(2));
    }
}
