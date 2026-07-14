<?php

namespace App\Services;

use App\Models\CrmDeal;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Zet een landingspagina-lead als deal in de Sales-pijplijn (fase "Lead"),
 * zodat sales 'm direct kan opvolgen vanuit admin → Sales / CRM.
 *
 * Gebruikt door:
 *  • LeadController::store  — elke nieuwe publieke lead direct doorzetten;
 *  • crm:backfill-leads     — bestaande leads eenmalig migreren.
 *
 * Dedupe op e-mail: bestaat er al een open deal voor dit adres, dan doen we
 * niets (won/lost-deals blokkeren niet — een terugkerende lead mag opnieuw
 * in de pijplijn).
 */
class CrmLeadPushService
{
    /**
     * @return CrmDeal|null  De aangemaakte deal, of null bij dedupe/fout.
     */
    public function push(Lead $lead, bool $silent = true): ?CrmDeal
    {
        try {
            $hasOpenDeal = CrmDeal::where('contact_email', $lead->email)
                ->where('status', 'open')
                ->exists();
            if ($hasOpenDeal) {
                return null;
            }

            $deal = CrmDeal::create([
                'title'          => $lead->email,
                'contact_email'  => $lead->email,
                'stage'          => 'lead',
                'position'       => (int) CrmDeal::where('stage', 'lead')->max('position') + 1,
                'tags'           => array_values(array_filter([$lead->source, $lead->utm_source])),
                'goal'           => 'Landingspagina-lead opvolgen',
                // Opvolgen binnen 2 dagen → kaart start als oranje "Opvolgen".
                'next_action_at' => now()->addDays(2),
            ]);

            $deal->activities()->create([
                'type'        => 'system',
                'body'        => 'Automatisch aangemaakt vanuit landingspagina-lead ('
                    . ($lead->source ?: 'onbekende bron') . ').',
                'happened_at' => now(),
            ]);

            return $deal;
        } catch (\Throwable $e) {
            // De publieke lead-intake mag hier nooit door falen.
            Log::warning('Lead → CRM-push mislukt', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
            if (! $silent) {
                throw $e;
            }
            return null;
        }
    }
}
