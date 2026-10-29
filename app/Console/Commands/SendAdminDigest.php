<?php

namespace App\Console\Commands;

use App\Mail\AdminDigestMail;
use App\Services\AdminDigestService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendAdminDigest extends Command
{
    protected $signature = 'digest:send
        {kind=day : day, week, month, quarter of year}
        {--date= : Peildatum (YYYY-MM-DD, Amsterdamse tijd); het overzicht dekt de periode vóór die dag. Standaard: nu}
        {--to= : Overschrijf de ontvanger(s), kommagescheiden}
        {--preview= : Schrijf de HTML naar dit bestand in plaats van te mailen}';

    protected $description = 'Verstuur het admin-overzicht (aanmeldingen, leads, abonnementen en totaalstand) over de vorige dag/week/maand/kwartaal/jaar.';

    public function handle(AdminDigestService $digest): int
    {
        $kind = (string) $this->argument('kind');
        if (! in_array($kind, AdminDigestService::KINDS, true)) {
            $this->error('Onbekend type "' . $kind . '". Kies uit: ' . implode(', ', AdminDigestService::KINDS) . '.');

            return self::INVALID;
        }

        $now = $this->option('date')
            ? CarbonImmutable::parse($this->option('date') . ' 07:00', AdminDigestService::TIMEZONE)
            : null;

        $mail = new AdminDigestMail($kind, $digest->build($kind, $now));

        if ($path = $this->option('preview')) {
            file_put_contents($path, $mail->render());
            $this->info("Voorbeeld geschreven naar {$path}");

            return self::SUCCESS;
        }

        $to = $this->option('to')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('to')))))
            : config('admin_digest.recipients');

        if (! $to) {
            $this->error('Geen ontvanger ingesteld (ADMIN_DIGEST_TO of --to).');

            return self::FAILURE;
        }

        Mail::to($to)->send($mail);
        $this->info("Overzicht '{$kind}' verstuurd naar " . implode(', ', $to) . '.');

        return self::SUCCESS;
    }
}
