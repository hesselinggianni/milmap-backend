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

    // Frequentie, roepnaam en cryptofill zijn COMSEC — versleuteld at rest.
    // De kolommen zijn daarvoor verbreed naar TEXT (varchar(32/64) was te
    // krap voor een versleutelde waarde); zie de migratie
    // 2026_08_10_200000_encrypt_sensitive_operational_data.
    protected $casts = [
        'is_primary' => 'boolean',
        'frequency'  => 'encrypted',
        'callsign'   => 'encrypted',
        'encryption' => 'encrypted',
    ];

    public function mission()
    {
        return $this->belongsTo(Mission::class);
    }
}
