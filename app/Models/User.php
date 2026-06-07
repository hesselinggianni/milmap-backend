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
            'last_seen_at'      => 'datetime',
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

    // ── Presence ("last online") ───────────────────────────────────

    /**
     * Whether this user exposes their "last online" timestamp to others.
     * Opt-out via settings.show_last_seen; defaults to true (privacy-by-default
     * still applies — the value is only ever a coarse "last online", never live
     * location/typing — and the user can disable it in their account).
     */
    public function showsLastSeen(): bool
    {
        $settings = $this->settings ?? [];

        return ! array_key_exists('show_last_seen', $settings)
            || (bool) $settings['show_last_seen'];
    }

    /**
     * The last-online timestamp to expose to OTHER users, honouring the
     * show_last_seen opt-out. Returns null when hidden or never seen.
     */
    public function publicLastSeen(): ?string
    {
        if (! $this->showsLastSeen()) {
            return null;
        }

        return $this->last_seen_at?->toIso8601String();
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
            // Group the OR so it stays scoped to THIS user's subscriptions:
            // (not cancelled) OR (cancellation date still in the future).
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
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

        return self::tierForPrice($sub->stripe_price);
    }

    /**
     * Reverse-map a Stripe Price ID to its MilMap plan key
     * (pro_monthly | pro_yearly | team_monthly | team_yearly) using the
     * admin-configured effective price map. Returns null when the price
     * matches no configured slot.
     *
     * This is the fix for paid subscriptions showing as "starter": the
     * stored value is a Stripe Price ID (e.g. price_1Tf…), which never
     * literally contains "pro"/"team", so a substring match always failed.
     */
    public static function planKeyForPrice(?string $price): ?string
    {
        if (! $price) return null;

        foreach (\App\Http\Controllers\AdminBillingController::effectiveMap() as $key => $priceId) {
            if ($priceId && $priceId === $price) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Resolve a Stripe Price ID to a coarse plan tier
     * ('pro' | 'team' | 'starter'). Prefers the admin-configured price map;
     * falls back to a substring match for legacy/manually-created prices.
     */
    public static function tierForPrice(?string $price): string
    {
        if (! $price) return 'starter';

        $key = self::planKeyForPrice($price);
        if ($key) {
            return str_starts_with($key, 'team') ? 'team' : 'pro';
        }

        // Fallback for legacy prices whose ID/nickname encodes the tier.
        if (stripos($price, 'team') !== false) return 'team';
        if (stripos($price, 'pro')  !== false) return 'pro';

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
