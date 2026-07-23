<?php

namespace App\Strategy;

use App\Contracts\StrategyInterface;
use App\Indicators\Ema;
use App\Indicators\Rsi;
use App\Models\Position;
use Brick\Math\BigDecimal;

/**
 * Deterministic momentum strategy: buy on a bullish EMA crossover while RSI is
 * below an overbought guard; sell on a bearish crossover or when a stop-loss /
 * take-profit level is breached. Serves as the safe fallback and the benchmark
 * the LLM strategy is measured against.
 */
final class EmaCrossRsiStrategy implements StrategyInterface
{
    public function key(): string
    {
        return 'ema_cross_rsi';
    }

    public function decide(array $candles, ?Position $current, array $params): Signal
    {
        $fast = (int) ($params['fast'] ?? 12);
        $slow = (int) ($params['slow'] ?? 26);
        $rsiPeriod = (int) ($params['rsi_period'] ?? 14);
        $rsiOverbought = (float) ($params['rsi_overbought'] ?? 70);
        $stopLossPct = (float) ($params['stop_loss_pct'] ?? 0.03);
        $takeProfitPct = (float) ($params['take_profit_pct'] ?? 0.06);
        $sizePct = (float) ($params['position_size_pct'] ?? 0.20);

        $closes = array_map(fn ($c) => $c->close, $candles);
        if (count($closes) < $slow + 2) {
            return Signal::hold('insufficient candle history');
        }

        $lastClose = $closes[count($closes) - 1];
        $fastSeries = Ema::series($closes, $fast);
        $slowSeries = Ema::series($closes, $slow);
        $rsi = Rsi::calc($closes, $rsiPeriod);

        $fastNow = $fastSeries[count($fastSeries) - 1];
        $fastPrev = $fastSeries[count($fastSeries) - 2];
        $slowNow = $slowSeries[count($slowSeries) - 1];
        $slowPrev = $slowSeries[count($slowSeries) - 2];

        if ($fastNow === null || $fastPrev === null || $slowNow === null || $slowPrev === null || $rsi === null) {
            return Signal::hold('indicators not ready');
        }

        $meta = [
            'ema_fast' => (string) $fastNow,
            'ema_slow' => (string) $slowNow,
            'rsi' => (string) $rsi,
            'close' => (string) $lastClose,
        ];

        $hasPosition = $current !== null && BigDecimal::of((string) $current->quantity)->isGreaterThan(BigDecimal::zero());

        // --- Manage an open position: stop-loss / take-profit / bearish exit ---
        if ($hasPosition) {
            $entry = BigDecimal::of((string) $current->avg_entry_price);
            $stop = $entry->multipliedBy((string) (1 - $stopLossPct));
            $take = $entry->multipliedBy((string) (1 + $takeProfitPct));

            if ($lastClose->isLessThanOrEqualTo($stop)) {
                return Signal::sell('stop-loss hit', 0.9, $meta + ['stop' => (string) $stop]);
            }
            if ($lastClose->isGreaterThanOrEqualTo($take)) {
                return Signal::sell('take-profit hit', 0.8, $meta + ['take' => (string) $take]);
            }
            if ($this->crossedDown($fastPrev, $slowPrev, $fastNow, $slowNow)) {
                return Signal::sell('bearish EMA crossover', 0.7, $meta);
            }

            return Signal::hold('holding open position', $meta);
        }

        // --- No position: look for an entry ---
        if ($this->crossedUp($fastPrev, $slowPrev, $fastNow, $slowNow) && (float) (string) $rsi < $rsiOverbought) {
            return Signal::buy($sizePct, 'bullish EMA crossover, RSI below overbought', 0.7, $meta);
        }

        return Signal::hold('no entry signal', $meta);
    }

    private function crossedUp(BigDecimal $fastPrev, BigDecimal $slowPrev, BigDecimal $fastNow, BigDecimal $slowNow): bool
    {
        return $fastPrev->isLessThanOrEqualTo($slowPrev) && $fastNow->isGreaterThan($slowNow);
    }

    private function crossedDown(BigDecimal $fastPrev, BigDecimal $slowPrev, BigDecimal $fastNow, BigDecimal $slowNow): bool
    {
        return $fastPrev->isGreaterThanOrEqualTo($slowPrev) && $fastNow->isLessThan($slowNow);
    }
}
