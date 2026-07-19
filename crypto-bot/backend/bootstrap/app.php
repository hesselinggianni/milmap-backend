<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // Per-minute tick; withoutOverlapping so a slow tick never stacks.
        $schedule->command('bot:tick')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/bot-tick.log'));

        // Weekly virtual top-up (hard-capped in DepositService).
        $schedule->command('bot:top-up')
            ->weeklyOn(1, '08:00')
            ->appendOutputTo(storage_path('logs/bot-topup.log'));

        // Daily report summary.
        $schedule->command('bot:report')
            ->dailyAt('23:55')
            ->appendOutputTo(storage_path('logs/bot-report.log'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
