<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\FetchEmails; // Voeg het juiste pad toe naar je command
use App\Console\Commands\SendStatusNotification;
use App\Console\Commands\NotifyNewMail;
use App\Console\Commands\ArchiveUnverifiedUsers;
use App\Console\Commands\ProcessMailFollowups;
use App\Console\Commands\GenerateSalesTodos;
use App\Console\Commands\PayoutPartners;

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
        // Status-page-domeinen (milmap.nl/status) monitoren + uptime/incidenten
        // bijhouden. withoutOverlapping zodat trage checks niet opstapelen.
        $schedule->command('status:monitor')->everyMinute()->withoutOverlapping();

        // Nieuwe-mail notificaties voor de admin-app. withoutOverlapping zodat
        // een trage IMAP-poll de volgende run niet laat opstapelen.
        $schedule->command('mail:notify-new')
            ->everyMinute()
            ->withoutOverlapping();

        // Follow-up-campagnemails: scan elke minuut welke regels mogen uitgaan
        // (vertraging op dag-niveau, dus per minuut scannen is ruim voldoende).
        $schedule->command('mail:process-followups')
            ->everyMinute()
            ->withoutOverlapping();

        // Definitief verwijderen wat langer dan 60 dagen in de prullenbak zit.
        // Draait 's nachts (lage piek), met overlap-bescherming en logging.
        $schedule->command('trash:purge')
            ->dailyAt('03:15')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/trash-purge.log'));

        // Archiveer accounts die na 90 dagen nog niet zijn geverifieerd (en niet
        // betalen). Het e-mailadres komt daarmee vrij om opnieuw te registreren.
        $schedule->command('users:archive-unverified')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/archive-unverified.log'));

        // Genereer elke ochtend automatisch sales-taken uit de actieve signalen
        // (aflopende trials, churn-risico, niet-benaderde leads, enz.), zodat de
        // takenlijst dagelijks up-to-date staat met concrete verkoop-acties.
        $schedule->command('sales:generate-todos')
            ->dailyAt('07:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/sales-todos.log'));

        // Maandelijkse partneruitbetaling: alle pending commissies per partner
        // in één Stripe-Connect-transfer (minimum €10, zie PayoutPartners).
        $schedule->command('partners:payout')
            ->monthlyOn(1, '08:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/partner-payouts.log'));
    }


}
