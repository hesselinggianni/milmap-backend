<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Een deelnemer-plek van een KAKO-opdracht in app-modus (zie docs/kako-app-modus.md).
 * De sleutel naar de opdracht is participant_key = participants[].id.
 */
class KakoAppParticipant extends Model
{
    protected $table = 'kako_app_participants';

    protected $fillable = [
        'route_assignment_id', 'participant_key', 'user_id', 'display_name', 'status',
        'invite_code', 'invite_expires_at', 'accepted_at',
        'consent_version', 'consent_at', 'consent_withdrawn_at',
        'window_from', 'window_until', 'mode_left_at',
        'last_lat', 'last_lon', 'last_accuracy', 'last_position_at', 'last_seen_at',
    ];

    protected $casts = [
        'invite_expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'consent_at' => 'datetime',
        'consent_withdrawn_at' => 'datetime',
        'window_from' => 'datetime',
        'window_until' => 'datetime',
        'mode_left_at' => 'datetime',
        'last_position_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_lat' => 'float',
        'last_lon' => 'float',
        'last_accuracy' => 'float',
        'consent_version' => 'integer',
    ];

    /** Verborgen in JSON: een uitnodigingscode is een geheim dat alleen de beheerder-API bewust teruggeeft. */
    protected $hidden = ['invite_code'];

    public function assignment()
    {
        return $this->belongsTo(RouteAssignment::class, 'route_assignment_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function checkins()
    {
        return $this->hasMany(KakoAppCheckin::class, 'participant_id');
    }

    public function positions()
    {
        return $this->hasMany(KakoAppPosition::class, 'participant_id');
    }

    /** Is `$at` (standaard nu) binnen het tijdvenster? Zonder venster nooit. */
    public function inWindow($at = null): bool
    {
        if (! $this->window_from || ! $this->window_until) {
            return false;
        }
        $at = $at ? \Illuminate\Support\Carbon::parse($at) : now();

        return $at->greaterThanOrEqualTo($this->window_from) && $at->lessThanOrEqualTo($this->window_until);
    }

    /** Toestemming voor positie-delen is gegeven (en niet ingetrokken) voor de huidige tekstversie. */
    public function hasConsent(int $version): bool
    {
        return $this->consent_at !== null
            && $this->consent_withdrawn_at === null
            && (int) $this->consent_version >= $version;
    }
}
