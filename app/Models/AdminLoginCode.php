<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AdminLoginCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'code',
        'ip_address',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * Check if the code has expired
     */
    public function isExpired(): bool
    {
        return Carbon::now()->isAfter($this->expires_at);
    }

    /**
     * Check if the code has been used
     */
    public function hasBeenUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * Scope to get unexpired codes
     */
    public function scopeUnexpired($query)
    {
        return $query->where('expires_at', '>', Carbon::now());
    }

    /**
     * Scope to get unused codes
     */
    public function scopeUnused($query)
    {
        return $query->whereNull('used_at');
    }

    /**
     * Mark the code as used
     */
    public function markAsUsed(): void
    {
        $this->update(['used_at' => Carbon::now()]);
    }
}
