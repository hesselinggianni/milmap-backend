<?php

namespace App\Contracts;

use App\Exchange\Dto\Candle;
use App\Models\Position;
use App\Strategy\Signal;

/**
 * A pluggable trading strategy. Given a candle window and the current open
 * position, it returns a Signal (buy/sell/hold). Strategies are deterministic
 * where possible so they can be unit-tested and back-tested against the exact
 * same code path used live.
 */
interface StrategyInterface
{
    /** Stable key, e.g. "ema_cross_rsi" or "llm_claude". */
    public function key(): string;

    /**
     * @param  Candle[]  $candles  ascending, oldest first
     * @param  Position|null  $current  the open position for this market, if any
     * @param  array<string,mixed>  $params  strategy parameters from strategy_configs
     */
    public function decide(array $candles, ?Position $current, array $params): Signal;
}
