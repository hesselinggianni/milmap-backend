<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerReferral extends Model
{
    protected $fillable = [
        'partner_id', 'referred_user_id', 'referral_code',
        'discount_applied', 'commission_rate_snapshot', 'converted_at',
    ];

    protected $casts = [
        'discount_applied'         => 'float',
        'commission_rate_snapshot' => 'float',
        'converted_at'             => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(PartnerCommission::class);
    }
}
