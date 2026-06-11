<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\FetchEmails; // Voeg het juiste pad toe naar je command

class Kernel extends ConsoleKernel
{
    /**
     * Definieer de artisan commands van de console.
     *
     * @var array
     */
    protected $commands = [
        FetchEmails::class, // Voeg het command toe
    ];

    /**
     * Definieer de geplande opdrachten voor de applicatie.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Voer de fetchEmails taak elke minuut uit
        $schedule->command('emails:fetch')->everyMinute();
        $schedule->command('check:status')->everyMinute();

        // Definitief verwijderen wat langer dan 60 dagen in de prullenbak zit.
        // Draait 's nachts (lage piek), met overlap-bescherming en logging.
        $schedule->command('trash:purge')
            ->dailyAt('03:15')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/trash-purge.log'));
    }


}
