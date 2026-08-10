<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GarminAccount extends Model
{
    protected $fillable = [
        'user_id',
        'garmin_user_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'scope',
        'devices',
        'connected_at',
        'last_synced_at',
    ];

    protected $casts = [
        'devices'         => 'array',
        'expires_at'      => 'datetime',
        'connected_at'    => 'datetime',
        'last_synced_at'  => 'datetime',
        // Tokens versleuteld in rust, net als social_accounts.
        'access_token'    => 'encrypted',
        'refresh_token'   => 'encrypted',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Binnen 5 minuten verlopend token telt als "moet ververst worden". */
    public function isTokenExpiringSoon(): bool
    {
        return $this->expires_at->subMinutes(5)->isPast();
    }
}
