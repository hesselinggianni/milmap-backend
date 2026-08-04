<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\FetchEmails; // Voeg het juiste pad toe naar je command
use App\Console\Commands\SendStatusNotification;
use App\Console\Commands\NotifyNewMail;
use App\Console\Commands\ArchiveUnverifiedUsers;
use App\Console\Commands\ProcessMailFollowups;
use App\Console\Commands\GenerateSalesTodos;
use App\Console\Commands\PayoutPartners;
use App\Console\Commands\MonitorPageSpeed;

class Kernel extends ConsoleKernel
{
    /**
     * Definieer de artisan commands van de console.
     *
     * @var array
     */
    protected $commands = [
        FetchEmails::class, // Voeg het command toe
        SendStatusNotification::class,
        NotifyNewMail::class,
        ArchiveUnverifiedUsers::class,
        ProcessMailFollowups::class,
        GenerateSalesTodos::class,
        PayoutPartners::class,
        MonitorPageSpeed::class,
    ];

    // De geplande opdrachten staan niet meer hier: deze schedule()-methode
    // wordt door de Laravel 11-bootstrap (bootstrap/app.php) niet geladen
    // (geen ->withSchedule()), dus alles hieronder draaide nooit via cron.
    // Zie routes/console.php voor de actieve Schedule::command(...)-definities.
}
