<?php

namespace App\Services;

use App\Contracts\ExchangeInterface;
use App\Jobs\ExecuteOrderJob;
use App\Models\EventLog;
use App\Models\Order;
use App\Models\Position;
use App\Models\StrategyConfig;
use App\Strategy\Signal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The tick loop. For every active strategy config: sync candles, ask the
 * strategy for a signal, run it past the RiskManager, and — if allowed — create
 * an idempotent order and dispatch it for (simulated) execution. A portfolio
 * snapshot is written every tick so the equity curve accumulates.
 */
final class TradingEngine
{
    public function __construct(
        private readonly ExchangeInterface $exchange,
        private readonly CandleService $candles,
        private readonly StrategyResolver $strategies,
        private readonly RiskManager $risk,
        private readonly PortfolioService $portfolio,
    ) {}

    public function tick(): void
    {
        // 1. Global gate — kill-switch / master enable checked before doing anything.
        if (! config('bot.enabled', false)) {
            EventLog::record('tick', 'skipped: bot disabled', [], 'info');

            return;
        }
        if ($this->risk->isKillSwitchActive()) {
            EventLog::record('kill_switch', 'tick skipped: kill-switch active', [], 'warning');

            return;
        }

        $interval = (string) config('bot.candle.interval', '1h');

        foreach (StrategyConfig::where('is_active', true)->with('account')->get() as $config) {
            $account = $config->account;
            if (! $account || ! $account->is_active) {
                continue;
            }

            try {
                $this->runConfig($config, $interval);
            } catch (\Throwable $e) {
                EventLog::record('tick', 'config tick failed', [
                    'strategy' => $config->strategy_key,
                    'market' => $config->market,
                    'error' => $e->getMessage(),
                ], 'critical', $account->id);
            }

            // Equity-curve snapshot after each config tick.
            $this->portfolio->snapshot($account);
        }
    }

    private function runConfig(StrategyConfig $config, string $interval): void
    {
        $account = $config->account;
        $market = $config->market;

        // 2. Sync candles into the local cache.
        $this->candles->sync($market, $interval);
        $window = $this->candles->window($market, $interval);

        if ($window === []) {
            EventLog::record('tick', 'no candles available', ['market' => $market], 'warning', $account->id);

            return;
        }

        $position = Position::where('account_id', $account->id)
            ->where('market', $market)
            ->where('status', 'open')
            ->first();

        // 3. Ask the strategy.
        $strategy = $this->strategies->resolve($config->strategy_key);
        $signal = $strategy->decide($window, $position, $config->params ?? []);

        EventLog::record('signal', "{$config->strategy_key}: {$signal->side}", [
            'market' => $market,
            'reason' => $signal->reason,
            'confidence' => $signal->confidence,
            'meta' => $signal->meta,
        ], 'info', $account->id);

        // 4. Hold → nothing to do.
        if (! $signal->isActionable()) {
            return;
        }

        // 5. Risk check.
        $decision = $this->risk->check($signal, $account, $position);
        if (! $decision->allowed) {
            EventLog::record('risk_block', "blocked {$signal->side}: {$decision->reason}", [
                'market' => $market,
            ], 'warning', $account->id);

            return;
        }

        // 6. Determine quantity and create an idempotent order.
        $lastCandle = $window[count($window) - 1];
        $price = $this->exchange->ticker($market)->price;

        if ($signal->side === Signal::SELL) {
            if ($position === null) {
                return;
            }
            $qty = BigDecimal::of((string) $position->quantity);
        } else {
            $equity = $this->portfolio->value($account)['equity']->amount();
            $notional = $equity->multipliedBy((string) $decision->sizeFraction);
            $qty = $notional->dividedBy($price, 10, RoundingMode::HALF_DOWN);
        }

        if (! $qty->isGreaterThan(BigDecimal::zero())) {
            return;
        }

        // Deterministic idempotency key: one order per (account, market, bar, side).
        $clientOrderId = hash('sha256', implode(':', [
            $account->id, $market, $signal->side, $lastCandle->openTime,
        ]));

        $order = Order::firstOrCreate(
            ['client_order_id' => $clientOrderId],
            [
                'account_id' => $account->id,
                'market' => $market,
                'side' => $signal->side,
                'type' => 'market',
                'requested_qty' => (string) $qty,
                'status' => 'pending',
                'strategy_config_id' => $config->id,
                'signal_meta' => $signal->meta,
            ],
        );

        // Only dispatch freshly-created pending orders (idempotency).
        if ($order->wasRecentlyCreated) {
            EventLog::record('order_placed', "order {$signal->side} {$qty} {$market}", [
                'order_id' => $order->id,
                'client_order_id' => $clientOrderId,
            ], 'info', $account->id);

            ExecuteOrderJob::dispatch($order->id);
        }
    }
}
