<?php

namespace App\Exchange\Dto;

use App\Support\Money;
use App\Support\Quantity;
use Brick\Math\BigDecimal;

/** The outcome of a (simulated or real) fill. */
final readonly class OrderResult
{
    public function __construct(
        public string $market,
        public string $side,
        public Quantity $filledQuantity,
        public BigDecimal $filledPrice,   // effective price incl. slippage
        public Money $fee,                // fee charged in the quote asset
        public string $exchangeOrderId,
    ) {}

    /** Notional value of the fill (quantity * price), excluding fee. */
    public function notional(string $quoteAsset = 'EUR'): Money
    {
        return $this->filledQuantity->valueAt($this->filledPrice, $quoteAsset);
    }
}
