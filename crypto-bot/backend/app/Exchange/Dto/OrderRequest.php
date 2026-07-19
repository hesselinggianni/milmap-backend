<?php

namespace App\Exchange\Dto;

use App\Support\Quantity;

/** An intent to place an order. Market orders only in the MVP. */
final readonly class OrderRequest
{
    public function __construct(
        public string $market,        // e.g. "BTC-EUR"
        public string $side,          // "buy" | "sell"
        public Quantity $quantity,    // base-asset amount to trade
        public string $clientOrderId, // deterministic idempotency key
    ) {}
}
