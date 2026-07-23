<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Immutable asset quantity (e.g. an amount of BTC), tagged with its asset.
 *
 * Kept separate from Money so a price (Money per Quantity) is explicit and
 * accidental EUR/BTC mixing is impossible.
 */
final class Quantity
{
    private BigDecimal $amount;

    public function __construct(BigDecimal|string|int $amount, public readonly string $asset)
    {
        $this->amount = $amount instanceof BigDecimal ? $amount : BigDecimal::of((string) $amount);
    }

    public static function of(BigDecimal|string|int $amount, string $asset): self
    {
        return new self($amount, $asset);
    }

    public static function zero(string $asset): self
    {
        return new self(BigDecimal::zero(), $asset);
    }

    public function amount(): BigDecimal
    {
        return $this->amount;
    }

    public function plus(Quantity $other): self
    {
        return new self($this->amount->plus($other->amount), $this->asset);
    }

    public function minus(Quantity $other): self
    {
        return new self($this->amount->minus($other->amount), $this->asset);
    }

    /** Notional value of this quantity at the given unit price. */
    public function valueAt(BigDecimal|string $unitPrice, string $quoteAsset = 'EUR'): Money
    {
        return new Money($this->amount->multipliedBy((string) $unitPrice), $quoteAsset);
    }

    public function isPositive(): bool
    {
        return $this->amount->isGreaterThan(BigDecimal::zero());
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function toScale(int $scale = 10): string
    {
        return (string) $this->amount->toScale($scale, RoundingMode::HALF_DOWN);
    }

    public function __toString(): string
    {
        return $this->toScale();
    }
}
