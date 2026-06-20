<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissionRadioChannel extends Model
{
    protected $table = 'mission_radio_channels';

    protected $fillable = [
        'mission_id',
        'net_name',
        'frequency',
        'callsign',
        'encryption',
        'mode',
        'is_primary',
        'sort_order',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function mission()
    {
        return $this->belongsTo(Mission::class);
    }
}
