<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Eén check-in-poging bij een prikker (ook mislukte) — zie docs/kako-app-modus.md. */
class KakoAppCheckin extends Model
{
    public $timestamps = false;

    protected $table = 'kako_app_checkins';

    protected $fillable = [
        'route_assignment_id', 'participant_id', 'user_id', 'client_uuid', 'post_id', 'post_label',
        'role', 'method', 'attempted_at', 'received_at', 'deadline_at', 'lat', 'lon', 'accuracy',
        'distance_m', 'within_radius', 'result', 'device',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
        'received_at' => 'datetime',
        'deadline_at' => 'datetime',
        'lat' => 'float',
        'lon' => 'float',
        'accuracy' => 'float',
        'distance_m' => 'float',
        'within_radius' => 'boolean',
    ];

    public function participant()
    {
        return $this->belongsTo(KakoAppParticipant::class, 'participant_id');
    }
}
