<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eén opgenomen GPS-activiteit (hardlopen/fietsen/wandelen) van een gebruiker.
 *
 * @see \App\Http\Controllers\ActivityController
 */
class Activity extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'notes',
        'started_at',
        'ended_at',
        'distance_m',
        'moving_time_s',
        'elapsed_time_s',
        'elevation_gain_m',
        'avg_pace_s_per_km',
        'avg_speed_kmh',
        'avg_power_w',
        'calories',
        'points',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'distance_m' => 'integer',
        'moving_time_s' => 'integer',
        'elapsed_time_s' => 'integer',
        'elevation_gain_m' => 'integer',
        'avg_pace_s_per_km' => 'float',
        'avg_speed_kmh' => 'float',
        'avg_power_w' => 'float',
        'calories' => 'integer',
        'points' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
