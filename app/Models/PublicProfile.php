<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opt-in openbaar profiel van een gebruiker: de enige plek waar MilMap een
 * naam, foto en gepubliceerde routes aan vreemden toont.
 *
 * @see \App\Http\Controllers\PublicProfileController
 */
class PublicProfile extends Model
{
    /** Handles die we voor onszelf houden (routes, merknamen, misbruik). */
    public const RESERVED_HANDLES = [
        'admin', 'administrator', 'api', 'app', 'milmap', 'support', 'help', 'info', 'root',
        'system', 'staff', 'team', 'official', 'me', 'null', 'undefined', 'www', 'mail',
    ];

    /** Zo veel meter van begin/einde van een openbare track wordt weggeknipt. */
    public const ENDPOINT_TRIM_M = 200;

    // Zelfde waarden als de kolom-defaults, maar ook op een net aangemaakt model
    // aanwezig (Eloquent leest DB-defaults niet terug): privacy staat standaard aan.
    protected $attributes = [
        'enabled' => false,
        'hide_endpoints' => true,
    ];

    protected $fillable = [
        'user_id',
        'handle',
        'display_name',
        'bio',
        'enabled',
        'hide_endpoints',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'hide_endpoints' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Naam zoals vreemden die zien: de gekozen weergavenaam, anders de handle — nooit de echte naam of het e-mailadres. */
    public function publicName(): string
    {
        $name = trim((string) $this->display_name);

        return $name !== '' ? $name : $this->handle;
    }

    /** Alleen profielen die aan staan zijn vindbaar/leesbaar voor anderen. */
    public function scopeVisible($query)
    {
        return $query->where('enabled', true);
    }

    public static function normalizeHandle(?string $handle): string
    {
        return strtolower(trim((string) $handle));
    }
}
