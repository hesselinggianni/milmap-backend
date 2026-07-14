<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\CrmLeadPushService;
use Illuminate\Console\Command;

/**
 * Eenmalige migratie: bestaande landingspagina-leads als deals in de
 * Sales-pijplijn zetten (fase "Lead"). Idempotent — leads waarvoor al een
 * open deal met hetzelfde e-mailadres bestaat, worden overgeslagen, dus de
 * command is veilig opnieuw te draaien.
 *
 *   php artisan crm:backfill-leads          # doen
 *   php artisan crm:backfill-leads --dry    # alleen tellen, niets schrijven
 */
class CrmBackfillLeads extends Command
{
    protected $signature = 'crm:backfill-leads {--dry : Alleen tonen wat er zou gebeuren}';

    protected $description = 'Zet bestaande leads (landingspagina) als deals in de Sales-pijplijn';

    public function handle(CrmLeadPushService $pusher): int
    {
        $dry = (bool) $this->option('dry');
        $created = 0;
        $skipped = 0;

        foreach (Lead::orderBy('id')->cursor() as $lead) {
            if ($dry) {
                $exists = \App\Models\CrmDeal::where('contact_email', $lead->email)
                    ->where('status', 'open')
                    ->exists();
                $exists ? $skipped++ : $created++;
                continue;
            }

            // silent=false: bij een backfill willen we fouten juist zien.
            $deal = $pusher->push($lead, silent: false);
            $deal ? $created++ : $skipped++;
        }

        $label = $dry ? 'zou aanmaken' : 'aangemaakt';
        $this->info("Klaar: {$created} deal(s) {$label}, {$skipped} overgeslagen (al een open deal).");

        return self::SUCCESS;
    }
}
