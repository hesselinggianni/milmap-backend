<?php

namespace App\Console\Commands;

use App\Models\EventLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class HaltCommand extends Command
{
    protected $signature = 'bot:halt {--resume : Clear the halt flag instead of setting it}';

    protected $description = 'Flip the runtime kill-switch that halts all trading immediately.';

    public function handle(): int
    {
        if ($this->option('resume')) {
            Cache::forget('bot:halt');
            EventLog::record('kill_switch', 'runtime halt cleared (resumed)', [], 'warning');
            $this->info('Halt cleared — trading may resume (if BOT_ENABLED=true).');

            return self::SUCCESS;
        }

        Cache::forever('bot:halt', true);
        EventLog::record('kill_switch', 'runtime halt engaged', [], 'critical');
        $this->warn('Kill-switch engaged. All trading is halted until `bot:halt --resume`.');

        return self::SUCCESS;
    }
}
