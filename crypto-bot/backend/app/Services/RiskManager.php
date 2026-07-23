<?php

namespace App\Services;

use App\Models\Account;
use App\Models\EventLog;
use App\Models\Position;
use App\Strategy\Signal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Cache;

/**
 * The safety layer. Runs before every order and can veto it. Defaults are
 * deliberately conservative — this layer is the reason the bot is allowed to
 * exist. Exits (sells) are permitted while the circuit-breaker is tripped so
 * risk can always be closed; only the master kill-switch stops everything.
 */
final class RiskManager
{
    public function __construct(private readonly PortfolioService $portfolio) {}

    /** Master kill-switch: config flag OR a persisted runtime halt flag. */
    public function isKillSwitchActive(): bool
    {
        return (bool) config('bot.kill_switch', false) || Cache::get('bot:halt', false);
    }

    public function circuitBreakerKey(Account $account): string
    {
        return 'bot:circuit_breaker:'.$account->id.':'.now()->format('Y-m-d');
    }

    public function isCircuitBreakerTripped(Account $account): bool
    {
        return (bool) Cache::get($this->circuitBreakerKey($account), false);
    }

    public function tripCircuitBreaker(Account $account, string $reason): void
    {
        Cache::put($this->circuitBreakerKey($account), true, now()->endOfDay());
        EventLog::record('circuit_breaker', $reason, [], 'critical', $account->id);
    }

    public function check(Signal $signal, Account $account, ?Position $position): RiskDecision
    {
        if (! config('bot.enabled', false)) {
            return RiskDecision::block('bot disabled (master enable is off)');
        }

        if ($this->isKillSwitchActive()) {
            return RiskDecision::block('kill-switch active');
        }

        // Exits are always allowed (except under the kill-switch) so risk can be closed.
        if ($signal->side === Signal::SELL) {
            return RiskDecision::allow(0.0, 'exit permitted');
        }

        if ($signal->side !== Signal::BUY) {
            return RiskDecision::block('non-actionable signal');
        }

        // --- Buy-side guards ---
        if ($this->isCircuitBreakerTripped($account)) {
            return RiskDecision::block('circuit-breaker tripped — no new entries today');
        }

        $value = $this->portfolio->value($account);
        $equity = $value['equity']->amount();

        // Daily-loss circuit breaker.
        $maxDailyLoss = (float) config('bot.risk.max_daily_loss_pct', 0.05);
        $startEquity = $this->portfolio->equityAtStartOfDay($account);
        if ($startEquity !== null && $startEquity->isGreaterThan(BigDecimal::zero())) {
            $floor = $startEquity->multipliedBy((string) (1 - $maxDailyLoss));
            if ($equity->isLessThanOrEqualTo($floor)) {
                $this->tripCircuitBreaker($account, 'daily loss limit reached');

                return RiskDecision::block('daily loss limit reached');
            }
        }

        // Drawdown-from-peak circuit breaker.
        $maxDrawdown = (float) config('bot.risk.max_drawdown_pct', 0.20);
        $peak = $this->portfolio->peakEquity($account);
        if ($peak->isGreaterThan(BigDecimal::zero())) {
            $floor = $peak->multipliedBy((string) (1 - $maxDrawdown));
            if ($equity->isLessThanOrEqualTo($floor)) {
                $this->tripCircuitBreaker($account, 'max drawdown from peak reached');

                return RiskDecision::block('max drawdown from peak reached');
            }
        }

        // Position-size caps: clamp to the smaller of pct-of-equity and an absolute EUR cap.
        $maxPositionPct = (float) config('bot.risk.max_position_pct', 0.20);
        $maxPositionEur = (float) config('bot.risk.max_position_eur', 250);
        $requestedFraction = min($signal->sizeFraction, $maxPositionPct);

        if ($equity->isLessThanOrEqualTo(BigDecimal::zero())) {
            return RiskDecision::block('no equity available');
        }

        $notionalPct = $equity->multipliedBy((string) $requestedFraction);
        $notionalCap = BigDecimal::of((string) $maxPositionEur);
        $notional = $notionalPct->isGreaterThan($notionalCap) ? $notionalCap : $notionalPct;

        // Total-exposure cap ACROSS ALL COINS: the combined value of open
        // positions may not exceed this fraction of equity. Prevents several
        // markets each taking a full position and over-committing the account.
        $maxTotalExposurePct = (float) config('bot.risk.max_total_exposure_pct', 0.60);
        $allowedExposure = $equity->multipliedBy((string) $maxTotalExposurePct);
        $currentExposure = $value['positions_value']->amount();
        $room = $allowedExposure->minus($currentExposure);
        if ($room->isLessThanOrEqualTo(BigDecimal::zero())) {
            return RiskDecision::block('max total exposure across all markets reached');
        }
        if ($notional->isGreaterThan($room)) {
            $notional = $room; // clamp to remaining exposure room
        }

        // Minimum notional guard (reject dust).
        $minNotional = (float) config('bot.risk.min_order_eur', 10);
        if ($notional->isLessThan(BigDecimal::of((string) $minNotional))) {
            return RiskDecision::block('order below minimum notional');
        }

        // Convert the final capped notional back to a fraction of equity.
        $finalFraction = (float) (string) $notional->dividedBy($equity, 8, \Brick\Math\RoundingMode::HALF_DOWN);

        return RiskDecision::allow($finalFraction, 'within risk limits');
    }
}
