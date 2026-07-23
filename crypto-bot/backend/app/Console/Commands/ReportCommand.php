<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Position;
use App\Models\Trade;
use App\Services\PortfolioService;
use App\Services\RiskManager;
use Illuminate\Console\Command;

class ReportCommand extends Command
{
    protected $signature = 'bot:report';

    protected $description = 'Print portfolio equity, open positions, P&L and halt status.';

    public function handle(PortfolioService $portfolio, RiskManager $risk): int
    {
        $this->line('Mode: '.(config('bot.real.enabled') ? 'LIVE' : 'PAPER').' | kill-switch: '.($risk->isKillSwitchActive() ? 'ON' : 'off'));

        foreach (Account::where('is_active', true)->get() as $account) {
            $v = $portfolio->value($account);
            $this->newLine();
            $this->info("Account #{$account->id} — {$account->name}");
            $this->table(['Metric', 'EUR'], [
                ['Equity', $v['equity']->toScale(2)],
                ['Cash', $v['cash']->toScale(2)],
                ['Positions value', $v['positions_value']->toScale(2)],
                ['Unrealized P&L', $v['unrealized']->toScale(2)],
                ['Realized P&L (cum)', $v['realized_cum']->toScale(2)],
            ]);

            $positions = Position::where('account_id', $account->id)->where('status', 'open')->get();
            if ($positions->isNotEmpty()) {
                $this->table(['Market', 'Qty', 'Avg entry'], $positions->map(fn (Position $p) => [
                    $p->market, $p->quantity, $p->avg_entry_price,
                ])->all());
            }

            $todayTrades = Trade::where('account_id', $account->id)->whereDate('executed_at', now()->toDateString())->count();
            $this->line("Trades today: {$todayTrades} | circuit-breaker: ".($risk->isCircuitBreakerTripped($account) ? 'TRIPPED' : 'ok'));
        }

        return self::SUCCESS;
    }
}
