<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\StrategyConfig;
use App\Models\WalletBalance;
use Illuminate\Console\Command;

class SeedAccountCommand extends Command
{
    protected $signature = 'bot:seed-account {--name=Paper account} {--market=BTC-EUR} {--strategy=ema_cross_rsi}';

    protected $description = 'Create a paper account with a virtual EUR balance and an active strategy config.';

    public function handle(): int
    {
        $account = Account::firstOrCreate(
            ['name' => $this->option('name')],
            ['mode' => 'paper', 'base_currency' => 'EUR', 'is_active' => true],
        );

        $starting = (string) config('bot.paper.starting_balance_eur', 1000);
        WalletBalance::firstOrCreate(
            ['account_id' => $account->id, 'asset' => 'EUR'],
            ['available' => $starting, 'reserved' => '0'],
        );

        $strategy = $this->option('strategy');
        StrategyConfig::firstOrCreate(
            ['account_id' => $account->id, 'strategy_key' => $strategy, 'market' => $this->option('market')],
            ['params' => $this->defaultParams($strategy), 'is_active' => true],
        );

        $this->info("Seeded account #{$account->id} with €{$starting} and an active '{$strategy}' config on {$this->option('market')}.");
        $this->warn('Remember: set BOT_ENABLED=true to let the scheduler act. Real trading stays disabled.');

        return self::SUCCESS;
    }

    private function defaultParams(string $strategy): array
    {
        return match ($strategy) {
            'llm_claude' => [
                'max_position_size_pct' => 0.20,
                'max_calls_per_day' => 24,
            ],
            default => [
                'fast' => 12,
                'slow' => 26,
                'rsi_period' => 14,
                'rsi_overbought' => 70,
                'stop_loss_pct' => 0.03,
                'take_profit_pct' => 0.06,
                'position_size_pct' => 0.20,
            ],
        };
    }
}
