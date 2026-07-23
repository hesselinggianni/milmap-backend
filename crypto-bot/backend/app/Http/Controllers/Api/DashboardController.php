<?php

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Models\EventLog;
use App\Models\Order;
use App\Models\PortfolioSnapshot;
use App\Models\Position;
use App\Models\StrategyConfig;
use App\Models\Trade;
use App\Services\PortfolioService;
use App\Services\RiskManager;
use App\Services\StrategyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only dashboard API. All monetary values are returned as strings to
 * preserve precision on the wire.
 */
class DashboardController
{
    public function __construct(
        private readonly PortfolioService $portfolio,
        private readonly RiskManager $risk,
    ) {}

    private function account(Request $request): Account
    {
        return $request->filled('account_id')
            ? Account::findOrFail($request->integer('account_id'))
            : Account::where('is_active', true)->firstOrFail();
    }

    public function status(Request $request): JsonResponse
    {
        $account = $this->account($request);

        return response()->json([
            'account_id' => $account->id,
            'account_name' => $account->name,
            'mode' => config('bot.real.enabled') ? 'live' : 'paper',
            'enabled' => (bool) config('bot.enabled', false),
            'kill_switch' => $this->risk->isKillSwitchActive(),
            'circuit_breaker' => $this->risk->isCircuitBreakerTripped($account),
        ]);
    }

    public function portfolio(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $v = $this->portfolio->value($account);

        $curve = PortfolioSnapshot::where('account_id', $account->id)
            ->orderBy('captured_at')
            ->limit(1000)
            ->get(['equity_eur', 'cash_eur', 'positions_value_eur', 'unrealized_pnl', 'captured_at']);

        return response()->json([
            'equity' => $v['equity']->toScale(2),
            'cash' => $v['cash']->toScale(2),
            'positions_value' => $v['positions_value']->toScale(2),
            'unrealized_pnl' => $v['unrealized']->toScale(2),
            'realized_pnl_cum' => $v['realized_cum']->toScale(2),
            'equity_curve' => $curve,
        ]);
    }

    public function positions(Request $request): JsonResponse
    {
        $account = $this->account($request);

        return response()->json(
            Position::where('account_id', $account->id)->orderByDesc('id')->get()
        );
    }

    public function orders(Request $request): JsonResponse
    {
        $account = $this->account($request);

        return response()->json(
            Order::where('account_id', $account->id)->orderByDesc('id')->limit(100)->get()
        );
    }

    public function trades(Request $request): JsonResponse
    {
        $account = $this->account($request);

        return response()->json(
            Trade::where('account_id', $account->id)->orderByDesc('executed_at')->limit(100)->get()
        );
    }

    public function events(Request $request): JsonResponse
    {
        $account = $this->account($request);

        return response()->json(
            EventLog::where('account_id', $account->id)->orderByDesc('id')->limit(200)->get()
        );
    }

    public function strategies(Request $request, StrategyResolver $resolver): JsonResponse
    {
        $account = $this->account($request);

        return response()->json([
            'available' => $resolver->available(),
            'configs' => StrategyConfig::where('account_id', $account->id)->get(),
        ]);
    }
}
