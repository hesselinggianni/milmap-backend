<?php

namespace App\Indicators;

use App\Exchange\Dto\Candle;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Average True Range — volatility measure, optional for volatility-scaled stops. */
final class AverageTrueRange
{
    private const SCALE = 12;

    /**
     * @param  Candle[]  $candles  ascending, oldest -> newest
     * @return BigDecimal|null  null when insufficient data
     */
    public static function calc(array $candles, int $period = 14): ?BigDecimal
    {
        $count = count($candles);
        if ($period < 1 || $count < $period + 1) {
            return null;
        }

        $trueRanges = [];
        for ($i = 1; $i < $count; $i++) {
            $c = $candles[$i];
            $prevClose = $candles[$i - 1]->close;
            $hl = $c->high->minus($c->low)->abs();
            $hc = $c->high->minus($prevClose)->abs();
            $lc = $c->low->minus($prevClose)->abs();
            $tr = $hl->max($hc)->max($lc);
            $trueRanges[] = $tr;
        }

        $window = array_slice($trueRanges, count($trueRanges) - $period, $period);
        $sum = BigDecimal::zero();
        foreach ($window as $tr) {
            $sum = $sum->plus($tr);
        }

        return $sum->dividedBy($period, self::SCALE, RoundingMode::HALF_UP);
    }
}
