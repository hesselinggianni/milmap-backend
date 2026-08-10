<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\RateLimiter;

/* Models */
use App\Models\User;
use App\Models\Map;

/* Policies */
use App\Policies\UserPolicy;
use App\Policies\MapPolicy;

/* Services */
use App\Services\UserService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(UserService::class, function ($app) {
            return new UserService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Map::class, MapPolicy::class);

        // Universeel uitgaand-maillog: elke uitgaande mail (transactioneel én
        // campagne, ook vanuit queue-workers) wordt als rij in sent_emails
        // vastgelegd, met een user/lead-koppeling op e-mailadres. Vóór verzending
        // (MessageSending) instrumenteert SentMailTracker het bericht met een
        // open-pixel + klik-tracking + UTM-attributie en maken we de rijen aan
        // (status 'pending'); ná succesvolle SMTP-overdracht (MessageSent) gaan
        // ze op 'sent' — een rij die 'pending' blijft is dus een mislukte
        // verzending. Mag nooit de daadwerkelijke verzending breken → alles in
        // stille try/catch.
        Event::listen(MessageSending::class, function (MessageSending $event) {
            try {
                $token   = \App\Support\SentMailTracker::prepare($event->message);
                $subject = mb_substr((string) $event->message->getSubject(), 0, 255);
                $now     = now();
                // Body ná instrumentatie = exact wat de ontvanger krijgt.
                $body = $event->message->getHtmlBody();
                $body = is_string($body) && $body !== '' ? $body : null;

                foreach ($event->message->getTo() as $address) {
                    $email = mb_strtolower(trim($address->getAddress()));
                    if ($email === '') {
                        continue;
                    }
                    \App\Models\SentEmail::create([
                        'email'   => $email,
                        'user_id' => \App\Models\User::where('email', $email)->value('id'),
                        'lead_id' => \App\Models\Lead::where('email', $email)->value('id'),
                        'token'     => $token,
                        'subject'   => $subject,
                        'mailer'    => mb_substr((string) config('mail.default'), 0, 40),
                        'status'    => 'pending',
                        'sent_at'   => $now,
                        'body_html' => $body,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        Event::listen(MessageSent::class, function (MessageSent $event) {
            try {
                $token = $event->sent->getOriginalMessage()->getHeaders()->getHeaderBody('X-MilMap-Track');
                if ($token) {
                    \App\Models\SentEmail::where('token', (string) $token)
                        ->where('status', 'pending')
                        ->update(['status' => 'sent']);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        RateLimiter::for('api', function (Request $request) {
            // Lokaal ruimer: de app-boot doet ~10 calls, waardoor een paar
            // pagina-navigaties binnen een minuut anders al 429 geven. Productie
            // stond op 60/min, maar een enkele pagina-load vuurt al 15-20
            // parallelle calls af — bij een paar navigaties/reloads binnen een
            // minuut liep dat al vast (inclusief /client-errors, waardoor
            // foutmeldingen niet eens meer in de admin belandden).
            return app()->environment('local', 'development')
                ? Limit::perMinute(600)
                : Limit::perMinute(300);
        });
    }
}
