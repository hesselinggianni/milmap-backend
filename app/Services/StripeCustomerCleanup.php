<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * Ruimt de Stripe-klant van een verwijderd account op.
 *
 * Gebruikt bij admin-verwijdering en na de 90 dagen bedenktijd van
 * zelf-verwijderen. Heeft de klant nooit iets betaald, dan wordt hij
 * verwijderd. Wél facturen of betalingen? Dan blijft de klant als
 * administratie staan, maar zetten we alles stop: abonnementen opgezegd en
 * betaalmethodes ontkoppeld, zodat er niets meer afgeschreven kan worden.
 *
 * Alles best-effort: een Stripe-storing mag het verwijderen van een account
 * nooit blokkeren, maar wordt gelogd zodat het handmatig rechtgezet kan worden.
 */
class StripeCustomerCleanup
{
    public const DELETED     = 'deleted';
    public const DEACTIVATED = 'deactivated';
    public const SKIPPED     = 'skipped';

    public function purgeOrDeactivate(?string $customerId, int $userId): string
    {
        if (! $customerId || ! ($stripe = $this->client($userId, $customerId))) {
            return self::SKIPPED;
        }

        try {
            $heeftHistorie = $this->hasBillingHistory($stripe, $customerId);
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                return self::DELETED; // bestaat al niet meer
            }
            $this->warn('historie opvragen mislukt', $userId, $customerId, $e);

            return self::SKIPPED;
        } catch (\Throwable $e) {
            $this->warn('historie opvragen mislukt', $userId, $customerId, $e);

            return self::SKIPPED;
        }

        if (! $heeftHistorie) {
            return $this->deleteCustomer($stripe, $customerId, $userId) ? self::DELETED : self::SKIPPED;
        }

        $this->deactivate($stripe, $customerId, $userId);

        return self::DEACTIVATED;
    }

    /**
     * Echte betaalhistorie = een factuur met een bedrag (geen concept/vervallen;
     * €0-trialfacturen tellen niet) of een geslaagde losse betaling.
     */
    private function hasBillingHistory(StripeClient $stripe, string $customerId): bool
    {
        foreach ($stripe->invoices->all(['customer' => $customerId, 'limit' => 100])->autoPagingIterator() as $invoice) {
            if (! in_array($invoice->status, ['draft', 'void'], true) && $invoice->total > 0) {
                return true;
            }
        }

        foreach ($stripe->charges->all(['customer' => $customerId, 'limit' => 100])->autoPagingIterator() as $charge) {
            if ($charge->status === 'succeeded' && $charge->amount > 0) {
                return true;
            }
        }

        return false;
    }

    /** Klant blijft bestaan, maar er kan niets meer lopen of afgeschreven worden. */
    private function deactivate(StripeClient $stripe, string $customerId, int $userId): void
    {
        // Rechtstreeks bij Stripe opvragen i.p.v. de lokale tabel: die kan
        // achterlopen (gemiste webhook), en dan bleef er alsnog iets lopen.
        try {
            foreach ($stripe->subscriptions->all(['customer' => $customerId, 'status' => 'all', 'limit' => 100])->autoPagingIterator() as $sub) {
                if (! in_array($sub->status, ['canceled', 'incomplete_expired'], true)) {
                    $stripe->subscriptions->cancel($sub->id, []);
                }
            }
        } catch (\Throwable $e) {
            $this->warn('abonnementen opzeggen mislukt', $userId, $customerId, $e);
        }

        try {
            foreach ($stripe->customers->allPaymentMethods($customerId, ['limit' => 100])->autoPagingIterator() as $pm) {
                $stripe->paymentMethods->detach($pm->id, []);
            }
        } catch (\Throwable $e) {
            $this->warn('betaalmethodes ontkoppelen mislukt', $userId, $customerId, $e);
        }
    }

    private function deleteCustomer(StripeClient $stripe, string $customerId, int $userId): bool
    {
        try {
            $stripe->customers->delete($customerId, []);

            return true;
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                return true; // al eerder (handmatig) verwijderd
            }
            $this->warn('klant verwijderen mislukt', $userId, $customerId, $e);
        } catch (\Throwable $e) {
            $this->warn('klant verwijderen mislukt', $userId, $customerId, $e);
        }

        return false;
    }

    protected function client(int $userId, string $customerId): ?StripeClient
    {
        $secret = config('billing.stripe_secret');
        if (! $secret) {
            Log::warning('[stripe-cleanup] geen Stripe-sleutel; klant niet opgeruimd', [
                'user_id' => $userId, 'customer' => $customerId,
            ]);

            return null;
        }

        return new StripeClient(['api_key' => $secret]);
    }

    private function warn(string $wat, int $userId, string $customerId, \Throwable $e): void
    {
        Log::warning("[stripe-cleanup] {$wat}", [
            'user_id' => $userId, 'customer' => $customerId, 'error' => $e->getMessage(),
        ]);
    }
}
