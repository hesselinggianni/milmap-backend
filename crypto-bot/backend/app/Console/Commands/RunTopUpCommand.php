<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\DepositService;
use Illuminate\Console\Command;

class RunTopUpCommand extends Command
{
    protected $signature = 'bot:top-up';

    protected $description = 'Apply a scheduled virtual deposit (paper mode) with hard lifetime caps.';

    public function handle(DepositService $deposits): int
    {
        foreach (Account::where('is_active', true)->get() as $account) {
            $amount = $deposits->topUp($account);
            $this->line("Account #{$account->id}: credited €{$amount}.");
        }

        return self::SUCCESS;
    }
}
