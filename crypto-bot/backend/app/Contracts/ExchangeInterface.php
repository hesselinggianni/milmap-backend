<?php

namespace App\Contracts;

use App\Exchange\Dto\Balance;
use App\Exchange\Dto\Candle;
use App\Exchange\Dto\OrderRequest;
use App\Exchange\Dto\OrderResult;
use App\Exchange\Dto\Ticker;

/**
 * The seam that keeps real money away from the engine.
 *
 * Public market-data methods work with no auth. Private methods move (virtual
 * or, in phase 3, real) funds and are only ever reached through the bound
 * implementation — PaperExchange by default.
 */
interface ExchangeInterface
{
    // --- Public market data (no auth) ---

    /** @return Candle[] ascending by time, oldest first */
    public function candles(string $market, string $interval, int $limit = 200): array;

    public function ticker(string $market): Ticker;

    // --- Private (account) ---

    /** @return Balance[] */
    public function balances(): array;

    public function placeOrder(OrderRequest $request): OrderResult;

    public function cancelOrder(string $market, string $orderId): void;

    // --- Introspection ---

    public function name(): string;   // 'bitvavo' | 'paper'

    /** True only when this implementation routes real, money-moving orders. */
    public function isLive(): bool;
}
