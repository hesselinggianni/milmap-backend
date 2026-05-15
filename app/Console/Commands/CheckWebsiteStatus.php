<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Website;

class CheckWebsiteStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the status of all monitored websites';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $websites = Website::all();

        foreach ($websites as $website) {
            try {
                $response = Http::timeout(5)->get($website->url);
                $website->status = $response->successful() ? 'online' : 'offline';
            } catch (\Exception $e) {
                $website->status = 'offline';
            }

            $website->save();
            $this->info("Checked: {$website->url} - Status: {$website->status}");
        }
    }
}
