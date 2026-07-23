<?php

namespace App\Exchange\Dto;

use Brick\Math\BigDecimal;

/** Available/reserved balance for a single asset. */
final readonly class Balance
{
    public function __construct(
        public string $asset,
        public BigDecimal $available,
        public BigDecimal $reserved,
    ) {}
}
