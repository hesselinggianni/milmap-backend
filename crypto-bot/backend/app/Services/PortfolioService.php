<?php

namespace App\Services;

use App\Contracts\ExchangeInterface;
use App\Models\Account;
use App\Models\PortfolioSnapshot;
use App\Models\Position;
use App\Models\WalletBalance;
use App\Support\Money;
use Brick\Math\BigDecimal;

/** Valuation and P&L for a virtual account. */
final class PortfolioService
{
    public function __construct(private readonly ExchangeInterface $exchange) {}

    public function cash(Account $account): Money
    {
        $wallet = WalletBalance::where('account_id', $account->id)
            ->where('asset', $account->base_currency)
            ->first();

        return new Money($wallet?->available ?? '0', $account->base_currency);
    }

    /**
     * @param  array<string,BigDecimal>|null  $priceOverrides  market => price, to avoid live calls (e.g. tests/backtest)
     * @return array{equity:Money,cash:Money,positions_value:Money,unrealized:Money,realized_cum:Money}
     */
    public function value(Account $account, ?array $priceOverrides = null): array
    {
        $cash = $this->cash($account);
        $positionsValue = Money::zero($account->base_currency);
        $unrealized = Money::zero($account->base_currency);
        $realizedCum = BigDecimal::zero();

        $positions = Position::where('account_id', $account->id)->get();
        foreach ($positions as $position) {
            $realizedCum = $realizedCum->plus(BigDecimal::of((string) $position->realized_pnl));

            $qty = BigDecimal::of((string) $position->quantity);
            if (! $qty->isGreaterThan(BigDecimal::zero())) {
                continue;
            }

            $price = $priceOverrides[$position->market] ?? $this->exchange->ticker($position->market)->price;
            $marketValue = new Money($qty->multipliedBy($price), $account->base_currency);
            $positionsValue = $positionsValue->plus($marketValue);

            $costBasis = new Money($qty->multipliedBy((string) $position->avg_entry_price), $account->base_currency);
            $unrealized = $unrealized->plus($marketValue->minus($costBasis));
        }

        return [
            'equity' => $cash->plus($positionsValue),
            'cash' => $cash,
            'positions_value' => $positionsValue,
            'unrealized' => $unrealized,
            'realized_cum' => new Money($realizedCum, $account->base_currency),
        ];
    }

    public function snapshot(Account $account, ?array $priceOverrides = null): PortfolioSnapshot
    {
        $v = $this->value($account, $priceOverrides);

        return PortfolioSnapshot::create([
            'account_id' => $account->id,
            'equity_eur' => $v['equity']->toScale(),
            'cash_eur' => $v['cash']->toScale(),
            'positions_value_eur' => $v['positions_value']->toScale(),
            'unrealized_pnl' => $v['unrealized']->toScale(),
            'realized_pnl_cum' => $v['realized_cum']->toScale(),
            'captured_at' => now(),
        ]);
    }

    /** Peak equity ever recorded, for drawdown checks. */
    public function peakEquity(Account $account): BigDecimal
    {
        $peak = PortfolioSnapshot::where('account_id', $account->id)->max('equity_eur');

        return BigDecimal::of((string) ($peak ?? '0'));
    }

    /** Equity at the start of today (first snapshot today), for daily-loss checks. */
    public function equityAtStartOfDay(Account $account): ?BigDecimal
    {
        $first = PortfolioSnapshot::where('account_id', $account->id)
            ->whereDate('captured_at', now()->toDateString())
            ->orderBy('captured_at')
            ->first();

        return $first ? BigDecimal::of((string) $first->equity_eur) : null;
    }
}
