<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerCommission extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_PAID     = 'paid';
    public const STATUS_REFUNDED = 'refunded';

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
}
