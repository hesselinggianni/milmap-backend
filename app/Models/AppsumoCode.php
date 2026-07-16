<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eén AppSumo lifetime-deal code. Zie de migratie voor de flow.
 */
class AppsumoCode extends Model
{
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_REDEEMED  = 'redeemed';

    protected $fillable = [
        'code',
        'status',
        'redeemed_by_user_id',
        'stripe_subscription_id',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
        ];
    }

    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE && is_null($this->redeemed_at);
    }

    /**
     * Normaliseer een ingevoerde code: trim + uppercase. AppSumo-codes zijn
     * hoofdletterongevoelig voor de gebruiker maar worden uppercase opgeslagen.
     */
    public static function normalize(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }
}
