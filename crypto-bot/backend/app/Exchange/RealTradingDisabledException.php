<?php

namespace App\Exchange;

use RuntimeException;

/**
 * Thrown whenever code attempts a real (money-moving) exchange operation
 * while the real-trading gate is closed. This is one of the three locks
 * that keep the MVP from ever touching real funds.
 */
final class RealTradingDisabledException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Real trading is disabled. Set BOT_REAL_ENABLED=true and provide '.
            'BITVAVO_API_KEY + BITVAVO_API_SECRET to enable live order routing.'
        );
    }
}
