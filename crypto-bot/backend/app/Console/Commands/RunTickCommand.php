<?php

namespace App\Console\Commands;

use App\Services\TradingEngine;
use Illuminate\Console\Command;

class RunTickCommand extends Command
{
    protected $signature = 'bot:tick';

    protected $description = 'Run one trading tick: sync candles, evaluate strategies, place (paper) orders.';

    public function handle(TradingEngine $engine): int
    {
        $engine->tick();
        $this->info('Tick complete.');

        return self::SUCCESS;
    }
}
