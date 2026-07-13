<?php

namespace App\Console\Commands;

use App\Mail\PartnerPayoutMail;
use App\Models\Partner;
use App\Models\PartnerCommission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\StripeClient;

/**
 * Maandelijkse partner-uitbetaling: alle pending commissies per goedgekeurde
 * partner met afgeronde Stripe-onboarding worden in één Stripe-Connect-transfer
 * overgemaakt (minimum €10, anders schuift het door naar volgende maand).
 * Draait op de 1e van de maand (zie Kernel) en is handmatig te starten vanuit
 * de admin-app (POST /admin/partners/payouts).
 */
class PayoutPartners extends Command
{
    protected $signature = 'partners:payout';

    protected $description = 'Betaal openstaande partnercommissies uit via Stripe Connect';

    private const MINIMUM_PAYOUT = 10.00;

    public function handle(): int
    {
        if (! config('billing.stripe_secret')) {
            $this->error('Stripe is niet geconfigureerd — geen uitbetalingen gedaan.');

            return self::FAILURE;
        }

        $stripe = new StripeClient(['api_key' => config('billing.stripe_secret')]);

        $partners = Partner::where('status', Partner::STATUS_APPROVED)
            ->whereNotNull('stripe_account_id')
            ->where('stripe_onboarding_complete', true)
            ->whereHas('commissions', fn ($q) => $q->where('status', PartnerCommission::STATUS_PENDING))
            ->get();

        $paidOut = 0;
        $skipped = 0;

        foreach ($partners as $partner) {
            $pending = $partner->commissions()
                ->where('status', PartnerCommission::STATUS_PENDING)
                ->get();
            $total = round((float) $pending->sum('commission_amount'), 2);

            if ($total < self::MINIMUM_PAYOUT) {
                $this->line("Partner {$partner->id} ({$partner->referral_code}): €{$total} < minimum, doorgeschoven.");
                $skipped++;
                continue;
            }

            try {
                $transfer = $stripe->transfers->create([
                    'amount'         => (int) round($total * 100),
                    'currency'       => 'eur',
                    'destination'    => $partner->stripe_account_id,
                    'transfer_group' => 'partner_payout_' . now()->format('Y_m'),
                    'metadata'       => [
                        'partner_id'  => (string) $partner->id,
                        'commissions' => $pending->pluck('id')->implode(','),
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::error('[partner] payout mislukt', [
                    'partner' => $partner->id, 'amount' => $total, 'error' => $e->getMessage(),
                ]);
                $this->error("Partner {$partner->id}: transfer mislukt — {$e->getMessage()}");
                continue;
            }

            PartnerCommission::whereIn('id', $pending->pluck('id'))->update([
                'status'             => PartnerCommission::STATUS_PAID,
                'stripe_transfer_id' => $transfer->id,
                'paid_at'            => now(),
            ]);

            try {
                Mail::to($partner->user->email)->send(new PartnerPayoutMail($partner, $total, $pending->count()));
            } catch (\Throwable $e) {
                Log::warning('[partner] payout-mail mislukt: ' . $e->getMessage());
            }

            $this->info("Partner {$partner->id} ({$partner->referral_code}): €{$total} uitbetaald ({$transfer->id}).");
            $paidOut++;
        }

        $this->info("Klaar: {$paidOut} uitbetaald, {$skipped} onder het minimum.");

        return self::SUCCESS;
    }
}
