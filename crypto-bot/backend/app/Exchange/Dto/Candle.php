<?php

namespace App\Exchange\Dto;

use Brick\Math\BigDecimal;

/** A single OHLCV candle. Prices are BigDecimal — never floats. */
final readonly class Candle
{
    public function __construct(
        public int $openTime,        // unix milliseconds
        public BigDecimal $open,
        public BigDecimal $high,
        public BigDecimal $low,
        public BigDecimal $close,
        public BigDecimal $volume,
    ) {}

    /**
     * Build from Bitvavo's array shape:
     * [timestamp, open, high, low, close, volume] (all strings).
     */
    public static function fromBitvavo(array $row): self
    {
        return new self(
            openTime: (int) $row[0],
            open: BigDecimal::of((string) $row[1]),
            high: BigDecimal::of((string) $row[2]),
            low: BigDecimal::of((string) $row[3]),
            close: BigDecimal::of((string) $row[4]),
            volume: BigDecimal::of((string) $row[5]),
        );
    }
}
