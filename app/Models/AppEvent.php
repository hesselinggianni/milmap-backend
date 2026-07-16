<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * First-party gebruiksanalyse-event van de MilMap-app: een route-bezoek
 * (type=route) of een knop/actie (type=action). Geen PII — alleen een
 * dag-gebonden visitor-hash. Zie AppEventController.
 */
class AppEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'type', 'name', 'label', 'platform', 'device', 'country', 'is_authed',
        'locale', 'visitor_hash', 'meta', 'occurred_at',
    ];

    protected $casts = [
        'meta'        => 'array',
        'is_authed'   => 'boolean',
        'occurred_at' => 'datetime',
    ];
}
