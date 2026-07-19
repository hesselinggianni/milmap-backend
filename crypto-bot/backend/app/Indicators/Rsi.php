<?php

namespace App\Indicators;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Relative Strength Index using Wilder's smoothing. */
final class Rsi
{
    private const SCALE = 12;

    /**
     * @param  BigDecimal[]  $values  closing prices, oldest -> newest
     * @return BigDecimal|null  0..100, or null when insufficient data
     */
    public static function calc(array $values, int $period = 14): ?BigDecimal
    {
        $count = count($values);
        if ($period < 1 || $count < $period + 1) {
            return null;
        }

        // Initial average gain/loss over the first `period` changes.
        $gain = BigDecimal::zero();
        $loss = BigDecimal::zero();
        for ($i = 1; $i <= $period; $i++) {
            $change = $values[$i]->minus($values[$i - 1]);
            if ($change->isGreaterThan(BigDecimal::zero())) {
                $gain = $gain->plus($change);
            } else {
                $loss = $loss->plus($change->abs());
            }
        }
        $avgGain = $gain->dividedBy($period, self::SCALE, RoundingMode::HALF_UP);
        $avgLoss = $loss->dividedBy($period, self::SCALE, RoundingMode::HALF_UP);

        // Wilder smoothing across the remaining changes.
        $pMinus1 = $period - 1;
        for ($i = $period + 1; $i < $count; $i++) {
            $change = $values[$i]->minus($values[$i - 1]);
            $g = $change->isGreaterThan(BigDecimal::zero()) ? $change : BigDecimal::zero();
            $l = $change->isLessThan(BigDecimal::zero()) ? $change->abs() : BigDecimal::zero();
            $avgGain = $avgGain->multipliedBy($pMinus1)->plus($g)
                ->dividedBy($period, self::SCALE, RoundingMode::HALF_UP);
            $avgLoss = $avgLoss->multipliedBy($pMinus1)->plus($l)
                ->dividedBy($period, self::SCALE, RoundingMode::HALF_UP);
        }

        if ($avgLoss->isZero()) {
            return BigDecimal::of(100);
        }

        $rs = $avgGain->dividedBy($avgLoss, self::SCALE, RoundingMode::HALF_UP);
        // rsi = 100 - (100 / (1 + rs))
        $rsi = BigDecimal::of(100)->minus(
            BigDecimal::of(100)->dividedBy($rs->plus(BigDecimal::one()), self::SCALE, RoundingMode::HALF_UP)
        );

        return $rsi->toScale(4, RoundingMode::HALF_UP);
    }
}
