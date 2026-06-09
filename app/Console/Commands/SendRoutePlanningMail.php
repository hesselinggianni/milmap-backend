<?php

namespace App\Console\Commands;

use App\Mail\RoutePlanningUpdateMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class SendRoutePlanningMail extends Command
{
    protected $signature = 'marketing:route-planning {email : Het e-mailadres van de ontvanger}';

    protected $description = 'Verstuur de MilMap route-planning marketing-update naar een e-mailadres';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        $validator = Validator::make(['email' => $email], ['email' => 'required|email:rfc']);
        if ($validator->fails()) {
            $this->error("Ongeldig e-mailadres: '{$email}'");

            return self::FAILURE;
        }

        // Marketing-mail mag nooit naar een localhost-link verwijzen; val terug op de publieke site.
        $appUrl = rtrim((string) config('app.app_url'), '/');
        if ($appUrl === '' || str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1')) {
            $appUrl = 'https://milmap.nl';
        }

        try {
            Mail::to($email)->send(new RoutePlanningUpdateMail($appUrl));
        } catch (\Throwable $e) {
            $this->error('Versturen mislukt: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Marketing-update verstuurd naar {$email}");

        return self::SUCCESS;
    }
}
