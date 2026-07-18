<?php

namespace App\Services;

use App\Http\Controllers\AdminBillingController;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Houdt het aantal betaalde extra seats van een team-abonnement in sync met
 * Stripe. Een team-abonnement bevat `config('teams.included_seats')` seats; elke
 * seat daarbóven (owner + actieve leden) wordt afgerekend op de meter-prijs
 * `team_extra_seat` uit de admin billing-mapping.
 *
 * Werkt "best effort": ontbreekt de extra-seat-prijs of de Stripe-secret, dan
 * doet de service niets (de UI toont de seats wel, er wordt alleen niet
 * afgerekend). Fouten worden gelogd maar nooit doorgegooid, zodat ledenbeheer
 * nooit stukloopt op een facturatiehick-up.
 */
class TeamSeatBillingService
{
    /**
     * Synchroniseer de extra-seat-quantity van het team-abonnement met het
     * huidige aantal gebruikte seats. Retourneert een korte statusarray.
     */
    public function sync(Team $team): array
    {
        // Seats worden op ABONNEMENT-niveau geteld: één owner deelt de pool over
        // al zijn teams. We rekenen dus af op de actieve team-subscription van de
        // owner, met het totaal aantal extra seats over al zijn teams.
        $usage = Team::seatUsageForOwner((int) $team->owner_id);
        $extra = $usage['extra'];

        $priceId = AdminBillingController::effectiveMap()['team_extra_seat'] ?? null;
        $secret  = config('billing.stripe_secret');

        if (! $priceId || ! $secret) {
            // Nog niet geconfigureerd → alleen tellen, niet afrekenen.
            return ['synced' => false, 'reason' => 'extra_seat_price_unconfigured', 'extra_seats' => $extra];
        }

        $team->loadMissing('owner');
        $sub = $team->owner?->activeSubscription();
        if (! $sub || ! $sub->stripe_id) {
            return ['synced' => false, 'reason' => 'no_stripe_subscription', 'extra_seats' => $extra];
        }

        try {
            $stripe = new StripeClient(['api_key' => $secret]);
            $stripeSub = $stripe->subscriptions->retrieve($sub->stripe_id, ['expand' => ['items']]);

            // Bestaand subscription-item op de extra-seat-prijs zoeken.
            $seatItem = null;
            foreach ($stripeSub->items->data as $item) {
                if (($item->price->id ?? null) === $priceId) {
                    $seatItem = $item;
                    break;
                }
            }

            if ($extra <= 0) {
                // Geen extra seats meer: bestaand item verwijderen.
                if ($seatItem) {
                    $stripe->subscriptionItems->delete($seatItem->id, [
                        'proration_behavior' => 'create_prorations',
                    ]);
                }
            } elseif ($seatItem) {
                if ((int) $seatItem->quantity !== $extra) {
                    $stripe->subscriptionItems->update($seatItem->id, [
                        'quantity'           => $extra,
                        'proration_behavior' => 'create_prorations',
                    ]);
                }
            } else {
                $stripe->subscriptionItems->create([
                    'subscription'       => $sub->stripe_id,
                    'price'              => $priceId,
                    'quantity'           => $extra,
                    'proration_behavior' => 'create_prorations',
                ]);
            }

            return ['synced' => true, 'extra_seats' => $extra];
        } catch (\Throwable $e) {
            Log::warning('team.seat-billing: sync mislukt', [
                'team'  => $team->id,
                'extra' => $extra,
                'error' => $e->getMessage(),
            ]);

            return ['synced' => false, 'reason' => 'stripe_error', 'extra_seats' => $extra];
        }
    }
}
