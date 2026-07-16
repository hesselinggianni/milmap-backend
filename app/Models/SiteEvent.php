<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * First-party analytics-event van milmap.nl (pageview of doel-event).
 * Geen PII: alleen een dag-gebonden visitor-hash. Zie SiteEventController.
 */
class SiteEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event', 'path', 'referrer', 'locale',
        'utm_source', 'utm_medium', 'utm_campaign',
        'visitor_hash', 'device', 'country', 'meta', 'occurred_at',
    ];

    protected $casts = [
        'meta'        => 'array',
        'occurred_at' => 'datetime',
    ];
}
