<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

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

        // Herkomst — 'native' (in-app opgenomen) of 'garmin' (via de Ping-
        // webhook geïmporteerd, zie App\Jobs\ImportGarminActivity).
        'source',
        'garmin_activity_id',
        'garmin_device_name',
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
    ];

    protected $hidden = ['points_ciphertext', 'notes_ciphertext'];

    protected function points(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                if (!empty($attributes['points_ciphertext'])) {
                    return json_decode(Crypt::decryptString($attributes['points_ciphertext']), true);
                }
                if (is_array($value)) return $value;
                return $value !== null ? json_decode($value, true) : null;
            },
            set: function ($value) {
                $points = is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
                return [
                    'points' => null,
                    'points_ciphertext' => Crypt::encryptString(json_encode($points, JSON_THROW_ON_ERROR)),
                    'start_lat' => $points[0]['lat'] ?? null,
                    'start_lon' => $points[0]['lon'] ?? null,
                ];
            },
        );
    }

    protected function notes(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => !empty($attributes['notes_ciphertext'])
                ? Crypt::decryptString($attributes['notes_ciphertext']) : $value,
            set: fn ($value) => [
                'notes' => null,
                'notes_ciphertext' => $value !== null ? Crypt::encryptString((string) $value) : null,
            ],
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ActivityPhoto::class);
    }
}
