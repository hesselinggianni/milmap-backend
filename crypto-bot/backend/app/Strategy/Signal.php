<?php

namespace App\Strategy;

/**
 * A strategy's decision for one tick. `sizeFraction` is the fraction of
 * available equity to allocate on a buy (0..1); it is ignored for sell/hold
 * (a sell closes the whole position in the MVP).
 */
final class Signal
{
    public const BUY = 'buy';
    public const SELL = 'sell';
    public const HOLD = 'hold';

    /**
     * @param  array<string,mixed>  $meta  indicator snapshot / model output, for the audit log
     */
    private function __construct(
        public readonly string $side,
        public readonly float $sizeFraction,
        public readonly string $reason,
        public readonly float $confidence,
        public readonly array $meta = [],
    ) {}

    public static function buy(float $sizeFraction, string $reason, float $confidence = 0.5, array $meta = []): self
    {
        return new self(self::BUY, max(0.0, min(1.0, $sizeFraction)), $reason, $confidence, $meta);
    }

    public static function sell(string $reason, float $confidence = 0.5, array $meta = []): self
    {
        return new self(self::SELL, 0.0, $reason, $confidence, $meta);
    }

    public static function hold(string $reason = 'no signal', array $meta = []): self
    {
        return new self(self::HOLD, 0.0, $reason, 0.0, $meta);
    }

    public function isActionable(): bool
    {
        return $this->side !== self::HOLD;
    }
}
