<?php

namespace App\Console\Commands;

use App\Mail\NotificationMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Mail alle gebruikers die in hun account "Ontvang e-mailnotificaties bij
 * storingen of onderhoud" hebben aangezet (settings->notify_status_emails).
 *
 * Voorbeelden:
 *   php artisan status:notify "We voeren vanavond 22:00-23:00 onderhoud uit." --type=onderhoud
 *   php artisan status:notify "De kaartlaag is tijdelijk gestoord, we werken aan een fix." --subject="Storing kaarten"
 */
class SendStatusNotification extends Command
{
    protected $signature = 'status:notify
        {message : De tekst van de melding}
        {--subject= : Eigen onderwerp (anders automatisch op basis van --type)}
        {--type=storing : storing|onderhoud — bepaalt het standaard-onderwerp}';

    protected $description = 'Stuur een storings-/onderhoudsmelding per e-mail naar alle aangemelde gebruikers';

    public function handle(): int
    {
        $type = strtolower((string) $this->option('type')) === 'onderhoud' ? 'onderhoud' : 'storing';
        $subject = $this->option('subject')
            ?: ($type === 'onderhoud' ? 'Gepland onderhoud — MilMap' : 'Storingsmelding — MilMap');
        $body = trim((string) $this->argument('message'));

        if ($body === '') {
            $this->error('Geef een bericht mee.');
            return self::FAILURE;
        }

        // Alleen gebruikers die de opt-in expliciet op true hebben staan.
        $recipients = User::query()
            ->where('settings->notify_status_emails', true)
            ->whereNotNull('email')
            ->get(['id', 'email']);

        if ($recipients->isEmpty()) {
            $this->warn('Geen gebruikers aangemeld voor storing-/onderhoudsmeldingen.');
            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($recipients as $user) {
            try {
                // Synchroon versturen — een storingsmail wil je nú buiten hebben,
                // niet afhankelijk van een draaiende queue-worker.
                Mail::to($user->email)->send(new NotificationMail([
                    'subject' => $subject,
                    'body'    => $body,
                ]));
                $sent++;
            } catch (\Throwable $e) {
                $this->error("Mislukt voor {$user->email}: {$e->getMessage()}");
            }
        }

        $this->info("Verstuurd naar {$sent}/{$recipients->count()} gebruiker(s).");

        return self::SUCCESS;
    }
}
