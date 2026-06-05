<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionTrack extends Model
{
    protected $table = 'mission_tracks';

    protected $fillable = [
        'mission_id',
        'user_id',
        'lat',
        'lng',
        'altitude',
        'speed',
        'heading',
        'accuracy',
        'recorded_at',
    ];

    protected $casts = [
        'lat'         => 'float',
        'lng'         => 'float',
        'altitude'    => 'float',
        'speed'       => 'float',
        'heading'     => 'float',
        'accuracy'    => 'float',
        'recorded_at' => 'datetime',
    ];

    public function mission()
    {
        return $this->belongsTo(Mission::class, 'mission_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
