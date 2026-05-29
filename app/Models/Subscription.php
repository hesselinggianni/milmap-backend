<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'stripe_id',
        'stripe_status',
        'stripe_price',
        'quantity',
        'trial_ends_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'ends_at'       => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function active(): bool
    {
        return in_array($this->stripe_status, ['active', 'trialing']);
    }

    public function onTrial(): bool
    {
        return $this->stripe_status === 'trialing';
    }

    public function canceled(): bool
    {
        return ! is_null($this->ends_at);
    }

    public function ended(): bool
    {
        return $this->canceled() && $this->ends_at->isPast();
    }
}
