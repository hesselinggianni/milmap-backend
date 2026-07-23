<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\StrategyConfig;
use App\Models\WalletBalance;
use Illuminate\Console\Command;

class AddMarketCommand extends Command
{
    protected $signature = 'bot:add-market {market : e.g. ETH-EUR}
        {--strategy=ema_cross_rsi : ema_cross_rsi or llm_claude}
        {--account= : account id (default: first active)}
        {--inactive : create the config disabled}';

    protected $description = 'Add a coin/market for the bot to trade (own strategy config + wallet).';

    public function handle(): int
    {
        $account = $this->option('account')
            ? Account::findOrFail((int) $this->option('account'))
            : Account::where('is_active', true)->first();

        if (! $account) {
            $this->error('No active account. Run `php artisan bot:seed-account` first.');

            return self::FAILURE;
        }

        $market = strtoupper($this->argument('market'));
        if (! str_contains($market, '-')) {
            $this->error("Market must look like BASE-QUOTE, e.g. ETH-EUR (got '{$market}').");

            return self::FAILURE;
        }

        [$base] = explode('-', $market);

        // Ensure a wallet row exists for the base asset.
        WalletBalance::firstOrCreate(
            ['account_id' => $account->id, 'asset' => $base],
            ['available' => '0', 'reserved' => '0'],
        );

        $strategy = $this->option('strategy');
        $config = StrategyConfig::firstOrCreate(
            ['account_id' => $account->id, 'strategy_key' => $strategy, 'market' => $market],
            ['params' => $this->defaultParams($strategy), 'is_active' => ! $this->option('inactive')],
        );

        $state = $config->is_active ? 'active' : 'inactive';
        $this->info("Market {$market} added for account #{$account->id} with '{$strategy}' strategy ({$state}).");
        $this->line('The bot now trades this coin on the next tick (if BOT_ENABLED=true).');

        return self::SUCCESS;
    }

    private function defaultParams(string $strategy): array
    {
        return match ($strategy) {
            'llm_claude' => ['max_position_size_pct' => 0.20, 'max_calls_per_day' => 24],
            default => [
                'fast' => 12, 'slow' => 26, 'rsi_period' => 14, 'rsi_overbought' => 70,
                'stop_loss_pct' => 0.03, 'take_profit_pct' => 0.06, 'position_size_pct' => 0.20,
            ],
        };
    }
}
