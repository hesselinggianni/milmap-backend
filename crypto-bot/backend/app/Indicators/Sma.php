<?php

namespace App\Indicators;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Simple Moving Average. Pure, side-effect-free. */
final class Sma
{
    /**
     * @param  BigDecimal[]  $values  oldest -> newest
     * @return BigDecimal|null  null when there is insufficient data
     */
    public static function calc(array $values, int $period): ?BigDecimal
    {
        $count = count($values);
        if ($period < 1 || $count < $period) {
            return null;
        }

        $window = array_slice($values, $count - $period, $period);
        $sum = BigDecimal::zero();
        foreach ($window as $v) {
            $sum = $sum->plus($v);
        }

        return $sum->dividedBy($period, 12, RoundingMode::HALF_UP);
    }
}
