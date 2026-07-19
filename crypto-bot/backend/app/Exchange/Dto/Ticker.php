<?php

namespace App\Exchange\Dto;

use Brick\Math\BigDecimal;

/** Latest price for a market. */
final readonly class Ticker
{
    public function __construct(
        public string $market,
        public BigDecimal $price,
    ) {}
}
