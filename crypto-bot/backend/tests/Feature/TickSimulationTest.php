<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\EventLog;
use App\Models\Order;
use App\Models\PortfolioSnapshot;
use App\Models\StrategyConfig;
use App\Models\Trade;
use App\Models\WalletBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TickSimulationTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMarket(float $lastPrice = 110.0): void
    {
        // Flat at 100, then a jump — produces a bullish EMA crossover with fast=2/slow=3.
        $closes = array_merge(array_fill(0, 30, 100.0), [$lastPrice]);
        $rows = [];
        foreach ($closes as $i => $c) {
            $rows[] = [($i + 1) * 3_600_000, (string) $c, (string) $c, (string) $c, (string) $c, '1'];
        }
        $rows = array_reverse($rows); // Bitvavo returns newest-first.

        Http::fake([
            'api.bitvavo.com/v2/BTC-EUR/candles*' => Http::response($rows),
            'api.bitvavo.com/v2/ticker/price*' => Http::response(['market' => 'BTC-EUR', 'price' => (string) $lastPrice]),
        ]);
    }

    private function seedAccount(): Account
    {
        $account = Account::create(['name' => 'Test', 'mode' => 'paper', 'base_currency' => 'EUR', 'is_active' => true]);
        WalletBalance::create(['account_id' => $account->id, 'asset' => 'EUR', 'available' => '1000', 'reserved' => '0']);
        StrategyConfig::create([
            'account_id' => $account->id,
            'strategy_key' => 'ema_cross_rsi',
            'market' => 'BTC-EUR',
            'is_active' => true,
            'params' => [
                'fast' => 2, 'slow' => 3, 'rsi_period' => 14, 'rsi_overbought' => 200,
                'stop_loss_pct' => 0.03, 'take_profit_pct' => 0.06, 'position_size_pct' => 0.20,
            ],
        ]);

        return $account;
    }

    public function test_bullish_tick_creates_paper_order_and_trade(): void
    {
        config(['bot.enabled' => true, 'bot.kill_switch' => false]);
        $this->fakeMarket(110.0);
        $account = $this->seedAccount();

        $this->artisan('bot:tick')->assertSuccessful();

        $this->assertDatabaseHas('orders', ['account_id' => $account->id, 'side' => 'buy', 'status' => 'filled']);
        $this->assertSame(1, Trade::where('account_id', $account->id)->count());
        $this->assertTrue(WalletBalance::where('account_id', $account->id)->where('asset', 'BTC')->exists());
        $this->assertTrue(PortfolioSnapshot::where('account_id', $account->id)->exists());
        $this->assertTrue(EventLog::where('type', 'order_filled')->exists());
    }

    public function test_kill_switch_blocks_all_trading(): void
    {
        config(['bot.enabled' => true]);
        Cache::put('bot:halt', true, now()->addHour());
        $this->fakeMarket(110.0);
        $account = $this->seedAccount();

        $this->artisan('bot:tick')->assertSuccessful();

        $this->assertSame(0, Order::where('account_id', $account->id)->count());
        $this->assertTrue(EventLog::where('type', 'kill_switch')->exists());
    }

    public function test_disabled_bot_does_nothing(): void
    {
        config(['bot.enabled' => false]);
        $this->fakeMarket(110.0);
        $account = $this->seedAccount();

        $this->artisan('bot:tick')->assertSuccessful();

        $this->assertSame(0, Order::where('account_id', $account->id)->count());
    }
}
