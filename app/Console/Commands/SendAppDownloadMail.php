<?php

namespace App\Console\Commands;

use App\Mail\AppDownloadMail;
use App\Services\LaunchCouponService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Stuur de app-download-mail: App Store/Play-knoppen, een app-frame, "Download
 * nu" en een unieke Stripe-promotiecode (20% op het jaarabonnement, 1x, 1 jaar
 * geldig).
 *
 *   php artisan milmap:app-download jan@example.com --name="Jan"
 *   php artisan milmap:app-download jan@example.com --no-coupon   (zonder Stripe)
 */
class SendAppDownloadMail extends Command
{
    protected $signature = 'milmap:app-download
                            {email : E-mailadres van de ontvanger}
                            {--name= : Voornaam voor de aanhef}
                            {--no-coupon : Verstuur zonder Stripe-kortingscode}';

    protected $description = 'Verstuur de app-download-mail met een unieke 20%-jaarcoupon (Stripe).';

    public function handle(LaunchCouponService $coupons): int
    {
        $email = $this->argument('email');

        $code = null;
        $expiresLabel = null;

        if (! $this->option('no-coupon')) {
            try {
                $result = $coupons->createYearlyDiscountCode($email);
                $code = $result['code'];
                $expiresLabel = $result['expires_at']->locale('nl')->isoFormat('D MMMM YYYY');
                $this->info("Unieke code aangemaakt: {$code} (geldig t/m {$expiresLabel})");
            } catch (\Throwable $e) {
                $this->error('Kon geen Stripe-code aanmaken: ' . $e->getMessage());
                if (! $this->confirm('Toch versturen zonder kortingscode?', false)) {
                    return self::FAILURE;
                }
            }
        }

        Mail::to($email)->send(new AppDownloadMail(
            couponCode: $code ?? '—',
            couponExpiresLabel: $expiresLabel,
            appStoreUrl: config('milmap.app_store_url'),
            playStoreUrl: config('milmap.play_store_url'),
            webAppUrl: config('milmap.web_app_url'),
            recipientName: $this->option('name') ?: null,
        ));

        $this->info("App-download-mail verstuurd naar {$email}.");

        return self::SUCCESS;
    }
}
