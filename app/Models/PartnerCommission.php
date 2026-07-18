<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerCommission extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_PAID     = 'paid';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * Refund-hold: een pending commissie wordt pas uitbetaalbaar wanneer de
     * onderliggende betaling zo veel dagen oud is. Enige bron van waarheid,
     * ook gebruikt door de payout-cron (PayoutPartners) zodat de verwachte
     * uitbetaaldatum die we tonen exact overeenkomt met wat er echt gebeurt.
     */
    public const REFUND_HOLD_DAYS = 30;

    protected $fillable = [
        'partner_id', 'partner_referral_id', 'stripe_payment_intent_id',
        'stripe_invoice_id', 'gross_amount', 'commission_amount',
        'status', 'stripe_transfer_id', 'paid_at',
    ];

    protected $casts = [
        'gross_amount'      => 'float',
        'commission_amount' => 'float',
        'paid_at'           => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(PartnerReferral::class, 'partner_referral_id');
    }

    /**
     * Verwachte uitbetaaldatum van deze commissie.
     *
     * - paid      → de werkelijke uitbetaaldatum (paid_at).
     * - refunded  → null (wordt niet uitbetaald).
     * - pending   → eerste 1e-van-de-maand ná het aflopen van de refund-hold
     *               (aanmaakdatum + REFUND_HOLD_DAYS), want de payout-cron
     *               draait op de 1e van de maand.
     *
     * Let op: het €10-minimum per partner kan een kleine som doorschuiven naar
     * een volgende maand; dat is niet in deze datum verwerkt (dat hangt van
     * toekomstige commissies af). Het is de vroegst mogelijke uitbetaaldatum.
     */
    public function expectedPayoutDate(): ?CarbonImmutable
    {
        if ($this->status === self::STATUS_REFUNDED) {
            return null;
        }

        if ($this->status === self::STATUS_PAID) {
            return $this->paid_at ? CarbonImmutable::parse($this->paid_at) : null;
        }

        $eligible = CarbonImmutable::parse($this->created_at)->addDays(self::REFUND_HOLD_DAYS);
        $payout   = $eligible->startOfMonth();

        // startOfMonth() kan vóór de eligible-datum liggen → schuif naar de
        // eerstvolgende 1e van de maand.
        if ($payout->lt($eligible)) {
            $payout = $payout->addMonth();
        }

        return $payout;
    }
}
