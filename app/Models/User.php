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
        'avatar_path',
        'email',
        'password',
        'language',
        'settings',
        'public_key',
        'key_escrow',
        'key_escrow_salt',
        'key_escrow_nonce',
        'key_escrow_ops',
        'key_escrow_mem',
        'key_escrow_alg',
        'key_escrow_unlock',
        'key_escrow_updated_at',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        'view_only',
        'share_uuid',
        'referred_by_id',
        'email_verified_at',
        'archived_at',
    ];

    /**
     * Geef elke nieuwe gebruiker automatisch een share_uuid zodat het frontend
     * direct na registratie kan delen zonder eerst een aparte call.
     */
    protected static function booted(): void
    {
        static::creating(function (User $u) {
            if (empty($u->share_uuid)) {
                $u->share_uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    /** Partnerprofiel (partners.milmap.nl) — los van de share-referral hieronder. */
    public function partner()
    {
        return $this->hasOne(\App\Models\Partner::class);
    }

    /** Wie heeft deze gebruiker geworven (via een ?utm_source=<share_uuid> link). */
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by_id');
    }

    /** Mensen die ik heb geworven — bron voor het admin-overzicht per "ambassadeur". */
    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by_id');
    }

    protected $hidden = [
        'password',
        'remember_token',
        // Encrypted key-escrow blob: never leak it in user listings/profiles.
        // It is returned only via the dedicated GET /chat/keys/escrow endpoint.
        'key_escrow',
        'key_escrow_salt',
        'key_escrow_nonce',
        'key_escrow_ops',
        'key_escrow_mem',
        'key_escrow_alg',
        'key_escrow_unlock',
    ];

    /**
     * Computed attributes that ride along in elke JSON-serialisatie van een
     * User. avatar_url leidt het volledige web-pad af uit avatar_path, zodat
     * de client (account, chatlijst, collaborator-lijsten) direct een bruikbare
     * URL krijgt zonder zelf paden te plakken.
     */
    protected $appends = ['avatar_url'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'archived_at'       => 'datetime',
            'password'          => 'hashed',
            'settings'          => 'array',
            'trial_ends_at'     => 'datetime',
            'view_only'         => 'boolean',
            'last_seen_at'      => 'datetime',
            'key_escrow_updated_at' => 'datetime',
            // At-rest encryption (APP_KEY) for the account unlock key: a DB
            // dump alone must not be enough to unwrap anyone's private key.
            'key_escrow_unlock'     => 'encrypted',
        ];
    }

    // ── Accessors ──────────────────────────────────────────────────

    /**
     * Volledige (publieke) URL naar de profielfoto, of null wanneer er geen
     * foto is ingesteld. Leest het ruwe attribuut zodat de accessor ook werkt
     * met een gedeeltelijke kolom-selectie (geeft dan simpelweg null terug),
     * en faalt nooit hard wanneer de disk-config ontbreekt.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        $path = $this->attributes['avatar_path'] ?? null;
        if (! $path) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        } catch (\Throwable $e) {
            return null;
        }
    }

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

    // ── Gratis proefperiode + premium-toegang ───────────────────────
    //
    // Elk nieuw account krijgt 7 dagen volledige (premium) toegang. Dit is een
    // *app-side* proef (géén automatische Stripe-afschrijving): `trial_ends_at`
    // op de user staat op registratie op nu+7 dagen. Zolang die datum in de
    // toekomst ligt heeft de gebruiker alle premiumfuncties. Daarna valt hij
    // terug op het gratis Starter-niveau (max 5 kaarten, basisroutes, MGRS,
    // online lagen, GPX-import) tot hij een abonnement neemt.

    /** Lengte van de gratis proefperiode na registratie, in dagen. */
    public const APP_TRIAL_DAYS = 7;

    /** Max aantal kaarten voor een gratis account (buiten proef/abonnement). */
    public const FREE_MAP_LIMIT = 5;

    /**
     * De premiumfuncties die na de proefperiode een abonnement vereisen. De
     * frontend leest deze lijst uit premiumState() om de UI op slot te zetten;
     * de backend dwingt ze af via de RequiresPremium-middleware (mutaties) en
     * de map-limiet in MapController.
     */
    public const PREMIUM_FEATURES = [
        'missions',          // missies aanmaken & beheren
        'chat',              // chatten (1-op-1 + missiekanaal)
        'nine_liner',        // MEDEVAC 9-liner
        'unlimited_maps',    // meer dan 5 kaarten
        'weather',           // weersintegratie
        'offline_maps',      // offline kaarten
        'area_markers',      // gebieden markeren
        'route_export',      // routekaart/PDF exporteren
        'teams',             // team aanmaken & beheren
        'team_shared_maps',  // gedeelde teamkaarten
        'live_location',     // live locatie delen
    ];

    /** Loopt de gratis proefperiode nu nog? */
    public function onAppTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /**
     * Volledige (premium) toegang: waar tijdens de proefperiode OF met een
     * actief betaald abonnement. Dit is de enige poort achter alle
     * PREMIUM_FEATURES.
     */
    public function hasPremiumAccess(): bool
    {
        return $this->onAppTrial() || $this->subscribed();
    }

    /** Heeft de gebruiker toegang tot een specifieke feature? */
    public function hasFeature(string $feature): bool
    {
        if (in_array($feature, self::PREMIUM_FEATURES, true)) {
            return $this->hasPremiumAccess();
        }

        return true; // basisfuncties zijn altijd beschikbaar
    }

    /** Resterende proefdagen (naar boven afgerond), of null als er geen proef loopt. */
    public function trialDaysLeft(): ?int
    {
        if (! $this->onAppTrial()) {
            return null;
        }

        return max(0, (int) ceil(now()->diffInHours($this->trial_ends_at, false) / 24));
    }

    /** Per-feature entitlement-map (true/false) voor de frontend. */
    public function entitlements(): array
    {
        $has = $this->hasPremiumAccess();

        return array_fill_keys(self::PREMIUM_FEATURES, $has);
    }

    /**
     * Compacte abonnements-/proefstatus voor de frontend (login + /users/me +
     * register). Stuurt de app precies genoeg om de trial-badge te tonen en de
     * premium-UI op slot te zetten zonder losse call.
     */
    public function premiumState(): array
    {
        return [
            'plan'            => $this->plan(),          // starter | pro | team
            'has_premium'     => $this->hasPremiumAccess(),
            'on_trial'        => $this->onAppTrial(),
            'trial_ends_at'   => $this->trial_ends_at?->toIso8601String(),
            'trial_days_left' => $this->trialDaysLeft(),
            'subscribed'      => $this->subscribed(),
            'map_limit'       => $this->hasPremiumAccess() ? null : self::FREE_MAP_LIMIT,
            'features'        => $this->entitlements(),
        ];
    }

    // ── E-mailverificatie ───────────────────────────────────────────
    //
    // Gratis (niet-betalende) accounts moeten hun e-mail bevestigen via een
    // link in de mail. Dit weert nep-accounts. Een Stripe-betaling geldt als
    // bewijs van echtheid: bij betaling wordt de e-mail automatisch geverifieerd
    // (markEmailVerified). Eenmaal geverifieerd blijft geverifieerd.

    /** Uren na registratie waarin een ongeverifieerd account nog vrij werkt. */
    public const VERIFICATION_GRACE_HOURS = 24;

    /** Dagen waarna een nog steeds ongeverifieerd, onbetaald account wordt gearchiveerd. */
    public const VERIFICATION_ARCHIVE_DAYS = 90;

    public function hasVerifiedEmail(): bool
    {
        return ! is_null($this->email_verified_at);
    }

    public function isArchived(): bool
    {
        return ! is_null($this->archived_at);
    }

    /** Zet de e-mail op geverifieerd (idempotent). Roep dit ook aan bij betaling. */
    public function markEmailVerified(): bool
    {
        if ($this->hasVerifiedEmail()) {
            return false;
        }
        $this->forceFill(['email_verified_at' => now()])->save();

        return true;
    }

    /** Verificatie vereist? Ja zolang niet geverifieerd én geen actief abonnement. */
    public function requiresEmailVerification(): bool
    {
        return ! $this->hasVerifiedEmail() && ! $this->subscribed();
    }

    /** Einde van de 24-uurs coulanceperiode na registratie. */
    public function verificationGraceEndsAt(): \Illuminate\Support\Carbon
    {
        return ($this->created_at ?? now())->copy()->addHours(self::VERIFICATION_GRACE_HOURS);
    }

    public function isWithinVerificationGrace(): bool
    {
        return now()->lt($this->verificationGraceEndsAt());
    }

    /**
     * Moet deze gebruiker de verificatie-"wall" zien? Pas ná de coulanceperiode
     * (24u na registratie) en alleen als verificatie nog vereist is.
     */
    public function isEmailVerificationWalled(): bool
    {
        return $this->requiresEmailVerification() && ! $this->isWithinVerificationGrace();
    }

    /** Hash voor de signed verificatie-URL (Laravel-standaard: sha1 van e-mail). */
    public function emailVerificationHash(): string
    {
        return sha1((string) $this->getEmailForVerification());
    }

    public function getEmailForVerification(): string
    {
        return (string) $this->email;
    }

    /** Compacte verificatie-status voor de frontend (login + /users/me). */
    public function verificationState(): array
    {
        return [
            'email_verified' => $this->hasVerifiedEmail(),
            'required'       => $this->requiresEmailVerification(),
            'grace_ends_at'  => $this->requiresEmailVerification()
                ? $this->verificationGraceEndsAt()->toIso8601String()
                : null,
            'walled'         => $this->isEmailVerificationWalled(),
        ];
    }

}
