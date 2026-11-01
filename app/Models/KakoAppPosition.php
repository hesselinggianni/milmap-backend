<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Eén positie-sample van een deelnemer (alleen met toestemming, binnen het venster). */
class KakoAppPosition extends Model
{
    public $timestamps = false;

    protected $table = 'kako_app_positions';

    protected $fillable = [
        'route_assignment_id', 'participant_id', 'user_id', 'recorded_at',
        'lat', 'lon', 'accuracy', 'speed', 'heading',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'lat' => 'float',
        'lon' => 'float',
        'accuracy' => 'float',
        'speed' => 'float',
        'heading' => 'float',
    ];
}
