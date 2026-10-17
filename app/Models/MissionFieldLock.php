<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Wie bewerkt welk veld van een missie op dit moment. Zie MissionLiveController.
 */
class MissionFieldLock extends Model
{
    protected $table = 'mission_field_locks';

    public $timestamps = false;

    protected $fillable = [
        'mission_id',
        'field',
        'user_id',
        'expires_at',
        'takeover_user_id',
        'takeover_requested_at',
        'takeover_status',
    ];

    protected $casts = [
        'user_id'               => 'integer',
        'takeover_user_id'      => 'integer',
        'expires_at'            => 'datetime',
        'takeover_requested_at' => 'datetime',
    ];
}
