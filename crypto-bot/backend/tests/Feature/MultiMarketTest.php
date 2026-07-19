<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Order;
use App\Models\StrategyConfig;
use App\Models\WalletBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultiMarketTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMarkets(float $price = 110.0): void
    {
        $closes = array_merge(array_fill(0, 30, 100.0), [$price]);
        $rows = [];
        foreach ($closes as $i => $c) {
            $rows[] = [($i + 1) * 3_600_000, (string) $c, (string) $c, (string) $c, (string) $c, '1'];
        }
        $rows = array_reverse($rows);

        // Wildcards cover any BASE-EUR market and the shared ticker endpoint.
        Http::fake([
            'api.bitvavo.com/v2/*/candles*' => Http::response($rows),
            'api.bitvavo.com/v2/ticker/price*' => Http::response(['price' => (string) $price]),
        ]);
    }

    private function seedTwoCoins(): Account
    {
        $account = Account::create(['name' => 'Multi', 'mode' => 'paper', 'base_currency' => 'EUR', 'is_active' => true]);
        WalletBalance::create(['account_id' => $account->id, 'asset' => 'EUR', 'available' => '1000', 'reserved' => '0']);

        foreach (['BTC-EUR', 'ETH-EUR'] as $market) {
            StrategyConfig::create([
                'account_id' => $account->id,
                'strategy_key' => 'ema_cross_rsi',
                'market' => $market,
                'is_active' => true,
                'params' => [
                    'fast' => 2, 'slow' => 3, 'rsi_period' => 14, 'rsi_overbought' => 200,
                    'stop_loss_pct' => 0.03, 'take_profit_pct' => 0.06, 'position_size_pct' => 0.20,
                ],
            ]);
        }

        return $account;
    }

    public function test_bot_trades_multiple_coins_in_one_tick(): void
    {
        config(['bot.enabled' => true, 'bot.kill_switch' => false]);
        $this->fakeMarkets();
        $account = $this->seedTwoCoins();

        $this->artisan('bot:tick')->assertSuccessful();

        $this->assertTrue(Order::where('market', 'BTC-EUR')->where('status', 'filled')->exists());
        $this->assertTrue(Order::where('market', 'ETH-EUR')->where('status', 'filled')->exists());
    }

    public function test_total_exposure_cap_limits_combined_positions(): void
    {
        // With a very low total-exposure cap, the second coin's entry is blocked.
        config([
            'bot.enabled' => true,
            'bot.kill_switch' => false,
            'bot.risk.max_total_exposure_pct' => 0.20, // only room for ~one 20% position
        ]);
        $this->fakeMarkets();
        $account = $this->seedTwoCoins();

        $this->artisan('bot:tick')->assertSuccessful();

        $filled = Order::where('account_id', $account->id)->where('status', 'filled')->count();
        $this->assertSame(1, $filled, 'Only one coin should get a position under the tight exposure cap.');
    }

    public function test_add_market_command_creates_config_and_wallet(): void
    {
        $account = Account::create(['name' => 'A', 'mode' => 'paper', 'base_currency' => 'EUR', 'is_active' => true]);

        $this->artisan('bot:add-market', ['market' => 'sol-eur', '--strategy' => 'llm_claude'])
            ->assertSuccessful();

        $this->assertDatabaseHas('strategy_configs', [
            'account_id' => $account->id, 'market' => 'SOL-EUR', 'strategy_key' => 'llm_claude', 'is_active' => true,
        ]);
        $this->assertDatabaseHas('wallet_balances', ['account_id' => $account->id, 'asset' => 'SOL']);
    }
}
