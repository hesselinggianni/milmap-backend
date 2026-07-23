<?php

namespace App\Services;

/** Outcome of a RiskManager check. */
final class RiskDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly float $sizeFraction = 0.0,
    ) {}

    public static function allow(float $sizeFraction, string $reason = 'ok'): self
    {
        return new self(true, $reason, $sizeFraction);
    }

    public static function block(string $reason): self
    {
        return new self(false, $reason, 0.0);
    }
}
