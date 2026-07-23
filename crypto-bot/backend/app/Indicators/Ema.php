<?php

namespace App\Indicators;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Exponential Moving Average, seeded with the SMA of the first `period` values. */
final class Ema
{
    private const SCALE = 12;

    /**
     * Full EMA series aligned to the input (nulls until the seed point).
     *
     * @param  BigDecimal[]  $values  oldest -> newest
     * @return array<int,BigDecimal|null>
     */
    public static function series(array $values, int $period): array
    {
        $count = count($values);
        $out = array_fill(0, $count, null);
        if ($period < 1 || $count < $period) {
            return $out;
        }

        // Seed: SMA of the first `period` values.
        $seed = BigDecimal::zero();
        for ($i = 0; $i < $period; $i++) {
            $seed = $seed->plus($values[$i]);
        }
        $prev = $seed->dividedBy($period, self::SCALE, RoundingMode::HALF_UP);
        $out[$period - 1] = $prev;

        // k = 2 / (period + 1)
        $k = BigDecimal::of(2)->dividedBy($period + 1, self::SCALE, RoundingMode::HALF_UP);
        $oneMinusK = BigDecimal::one()->minus($k);

        for ($i = $period; $i < $count; $i++) {
            // ema = value*k + prev*(1-k)
            $prev = $values[$i]->multipliedBy($k)
                ->plus($prev->multipliedBy($oneMinusK))
                ->toScale(self::SCALE, RoundingMode::HALF_UP);
            $out[$i] = $prev;
        }

        return $out;
    }

    /**
     * @param  BigDecimal[]  $values  oldest -> newest
     * @return BigDecimal|null  latest EMA, or null when insufficient data
     */
    public static function last(array $values, int $period): ?BigDecimal
    {
        $series = self::series($values, $period);

        return $series[count($series) - 1] ?? null;
    }
}
