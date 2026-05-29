<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'language',
        'settings',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'settings'          => 'array',
            'trial_ends_at'     => 'datetime',
        ];
    }

    // ── Relations ──────────────────────────────────────────────────

    public function maps()
    {
        return $this->hasMany(Map::class, 'owner_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class)->orderByDesc('created_at');
    }

    // ── Subscription helpers ────────────────────────────────────────

    /**
     * Get the user's active subscription (first active found).
     */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->whereIn('stripe_status', ['active', 'trialing'])
            ->whereNull('ends_at')
            ->orWhere('ends_at', '>', now())
            ->first();
    }

    public function subscribed(): bool
    {
        return ! is_null($this->activeSubscription());
    }

    public function plan(): string
    {
        $sub = $this->activeSubscription();
        if (! $sub) return 'starter';

        $price = $sub->stripe_price ?? '';
        if (str_contains($price, 'team')) return 'team';
        if (str_contains($price, 'pro'))  return 'pro';

        return 'starter';
    }

}
