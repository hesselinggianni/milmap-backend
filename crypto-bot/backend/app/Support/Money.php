<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Immutable monetary amount tagged with an asset/currency.
 *
 * All arithmetic is arbitrary-precision via brick/math — never floats.
 * Use this for EUR balances, notional values and P&L.
 */
final class Money
{
    private BigDecimal $amount;

    public function __construct(BigDecimal|string|int $amount, public readonly string $asset = 'EUR')
    {
        $this->amount = $amount instanceof BigDecimal ? $amount : BigDecimal::of((string) $amount);
    }

    public static function of(BigDecimal|string|int $amount, string $asset = 'EUR'): self
    {
        return new self($amount, $asset);
    }

    public static function zero(string $asset = 'EUR'): self
    {
        return new self(BigDecimal::zero(), $asset);
    }

    public function amount(): BigDecimal
    {
        return $this->amount;
    }

    public function plus(Money $other): self
    {
        $this->assertSameAsset($other);

        return new self($this->amount->plus($other->amount), $this->asset);
    }

    public function minus(Money $other): self
    {
        $this->assertSameAsset($other);

        return new self($this->amount->minus($other->amount), $this->asset);
    }

    public function multipliedBy(BigDecimal|string|int $factor): self
    {
        return new self($this->amount->multipliedBy((string) $factor), $this->asset);
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isGreaterThan(Money $other): bool
    {
        $this->assertSameAsset($other);

        return $this->amount->isGreaterThan($other->amount);
    }

    public function isLessThan(Money $other): bool
    {
        $this->assertSameAsset($other);

        return $this->amount->isLessThan($other->amount);
    }

    /** Fixed-scale string suitable for DECIMAL(30,10) storage. */
    public function toScale(int $scale = 10): string
    {
        return (string) $this->amount->toScale($scale, RoundingMode::HALF_UP);
    }

    public function __toString(): string
    {
        return $this->toScale();
    }

    private function assertSameAsset(Money $other): void
    {
        if ($this->asset !== $other->asset) {
            throw new \InvalidArgumentException("Cannot combine money of {$this->asset} with {$other->asset}.");
        }
    }
}
