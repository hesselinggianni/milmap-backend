<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialAccount extends Model
{
    protected $fillable = [
        'platform', 'access_token', 'refresh_token', 'board_id', 'meta', 'expires_at',
    ];

    protected $casts = [
        'meta'          => 'array',
        'expires_at'    => 'datetime',
        // Tokens versleuteld in rust.
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    /** Is dit platform geconfigureerd voor automatische publicatie? */
    public function isConnected(): bool
    {
        return ! empty($this->access_token)
            && ($this->platform !== 'pinterest' || ! empty($this->board_id));
    }
}
