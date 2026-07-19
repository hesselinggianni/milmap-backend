<?php

namespace App\Exchange;

use App\Contracts\ExchangeInterface;
use App\Exchange\Dto\Balance;
use App\Exchange\Dto\OrderRequest;
use App\Exchange\Dto\OrderResult;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Simulated exchange. Market data is REAL (delegated to Bitvavo's public API);
 * fills are simulated against the live price with configurable slippage and
 * fees. This is where paper-trading realism lives — un-modelled fees and
 * slippage are the #1 way paper results lie about live performance.
 */
final class PaperExchange implements ExchangeInterface
{
    public function __construct(
        private readonly BitvavoClient $marketData,
        private readonly int $feeBps = 25,       // Bitvavo taker ~0.25%
        private readonly int $slippageBps = 5,   // 0.05% modelled slippage
    ) {}

    public function name(): string
    {
        return 'paper';
    }

    public function isLive(): bool
    {
        return false;
    }

    public function candles(string $market, string $interval, int $limit = 200): array
    {
        return $this->marketData->candles($market, $interval, $limit);
    }

    public function ticker(string $market): \App\Exchange\Dto\Ticker
    {
        return $this->marketData->ticker($market);
    }

    /** Reads the virtual wallet from the database. */
    public function balances(): array
    {
        return \App\Models\WalletBalance::query()->get()
            ->map(fn ($w) => new Balance(
                asset: $w->asset,
                available: BigDecimal::of((string) $w->available),
                reserved: BigDecimal::of((string) $w->reserved),
            ))->all();
    }

    public function placeOrder(OrderRequest $request): OrderResult
    {
        $price = $this->ticker($request->market)->price;

        // Apply slippage against the taker: buys fill slightly higher, sells slightly lower.
        $slip = BigDecimal::of($this->slippageBps)->dividedBy(10000, 12, RoundingMode::HALF_UP);
        $factor = $request->side === 'buy'
            ? BigDecimal::one()->plus($slip)
            : BigDecimal::one()->minus($slip);
        $fillPrice = $price->multipliedBy($factor);

        $notional = $request->quantity->amount()->multipliedBy($fillPrice);
        $fee = $notional->multipliedBy(
            BigDecimal::of($this->feeBps)->dividedBy(10000, 12, RoundingMode::HALF_UP)
        );

        [$quote] = $this->splitMarket($request->market);

        return new OrderResult(
            market: $request->market,
            side: $request->side,
            filledQuantity: $request->quantity,
            filledPrice: $fillPrice,
            fee: new Money($fee, $quote),
            exchangeOrderId: 'paper-'.$request->clientOrderId,
        );
    }

    public function cancelOrder(string $market, string $orderId): void
    {
        // No resting orders in the paper MVP (market orders fill instantly).
    }

    /** @return array{0:string,1:string} [quoteAsset, baseAsset] for "BTC-EUR" => ["EUR","BTC"] */
    private function splitMarket(string $market): array
    {
        [$base, $quote] = explode('-', $market);

        return [$quote, $base];
    }
}
