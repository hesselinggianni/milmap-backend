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
        'public_key',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        'view_only',
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
            'view_only'         => 'boolean',
        ];
    }

    // ── Accessors ──────────────────────────────────────────────────

    /**
     * Volledige naam (voor- + achternaam). Valt terug op het e-mailadres
     * wanneer er geen naam is ingevuld, zodat de waarde nooit leeg/null is
     * (gebruikt door collaborator-lijsten en gebruikerszoekfunctie).
     */
    public function getFullNameAttribute(): string
    {
        $name = trim(sprintf('%s %s', $this->first_name ?? '', $this->last_name ?? ''));

        return $name !== '' ? $name : (string) $this->email;
    }

    // ── Relations ──────────────────────────────────────────────────

    public function maps()
    {
        return $this->hasMany(Map::class, 'owner_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class, 'owner_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class)->orderByDesc('created_at');
    }

    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_user')
            ->withPivot('last_read_at')
            ->withTimestamps();
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

    /**
     * Whether this account is limited to view-only access. True for accounts
     * that were auto-created from an e-mail invitation and that do not yet have
     * a paid subscription. Existing (non-invited) users have view_only=false and
     * are therefore never restricted by this flag.
     */
    public function isViewOnly(): bool
    {
        return (bool) $this->view_only && ! $this->subscribed();
    }

}
