<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'user_id', 'company_name', 'referral_code', 'status',
        'commission_rate', 'discount_rate', 'stripe_account_id',
        'stripe_onboarding_complete', 'website', 'description', 'approved_at',
        'agreement_accepted_at', 'agreement_confirmed_at', 'agreement_ip',
        'agreement_code', 'agreement_code_expires_at',
    ];

    protected $casts = [
        'commission_rate'            => 'float',
        'discount_rate'              => 'float',
        'stripe_onboarding_complete' => 'boolean',
        'approved_at'                => 'datetime',
        'agreement_accepted_at'      => 'datetime',
        'agreement_confirmed_at'     => 'datetime',
        'agreement_code_expires_at'  => 'datetime',
    ];

    /** De bevestigingscode hoort nooit in een API-response te lekken. */
    protected $hidden = ['agreement_code'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(PartnerReferral::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(PartnerCommission::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Overeenkomst volledig rond: geaccepteerd in het portaal én per e-mail
     * bevestigd. Pas daarna mag de referral-link gedeeld/gebruikt worden.
     */
    public function agreementComplete(): bool
    {
        return $this->agreement_accepted_at !== null
            && $this->agreement_confirmed_at !== null;
    }

    public function totalEarned(): float
    {
        return (float) $this->commissions()->where('status', PartnerCommission::STATUS_PAID)->sum('commission_amount');
    }

    public function pendingEarnings(): float
    {
        return (float) $this->commissions()->where('status', PartnerCommission::STATUS_PENDING)->sum('commission_amount');
    }

    /** De volledige deelbare referral-link voor deze partner. */
    public function referralUrl(): string
    {
        $appUrl = config('app.app_url', 'https://app.milmap.nl');

        return rtrim($appUrl, '/') . '/ref/' . $this->referral_code;
    }

    /**
     * Genereer een unieke, leesbare referral-code op basis van de (bedrijfs)naam,
     * bijv. JANSEN25. Zonder verwarrende tekens; cijfers als collision-suffix.
     */
    public static function generateReferralCode(string $name): string
    {
        $base = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 6));
        if ($base === '') {
            $base = 'MILMAP';
        }

        do {
            $code = $base . random_int(10, 99);
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }
}
