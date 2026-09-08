<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class UserLoginCode extends Model
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

    public function scopeUnexpired($query)
    {
        return $query->where('expires_at', '>', Carbon::now());
    }

    public function scopeUnused($query)
    {
        return $query->whereNull('used_at');
    }

    public function markAsUsed(): void
    {
        $this->update(['used_at' => Carbon::now()]);
    }
}
