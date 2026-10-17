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
use Illuminate\Broadcasting\BroadcastManager;
use App\Broadcasting\ResilientPusherBroadcaster;

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
        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(function ($token, bool $valid) {
            return $valid && app(\App\Services\ConnectDeviceLogin::class)->validSession($token);
        });

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
            // Authentication secrets must not enter the general email archive.
            if ($event->message->getHeaders()->has('X-Milmap-Sensitive')) return;
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
            // foutmeldingen niet eens meer in de admin belandden). Productie
            // draaide feitelijk op 600 (APP_ENV stond op local); dat houden we
            // aan, los van APP_ENV.
            if ($request->user()) {
                return Limit::perMinute(600)->by('user:'.$request->user()->getAuthIdentifier());
            }

            // Gasten per installatie, met een plafond per IP: duizenden
            // gebruikers achter één school-/kazerne-NAT mogen elkaar niet
            // buitensluiten (zie App\Support\ClientKey).
            $guest = Limit::perMinute(600)->by(\App\Support\ClientKey::forGuest($request));
            if (! \App\Support\ClientKey::installation($request)) {
                return $guest;
            }

            return [$guest, Limit::perMinute(600 * \App\Support\ClientKey::natFactor())->by('ip:'.$request->ip())];
        });

        // Connect-apparaatlogin (status, login, huisstijl): per apparaat-credential
        // i.p.v. per IP. Een klas van honderden beheerde iPads deelt één IP en logt
        // tegelijk in; het plafond per IP is alleen een vangnet tegen misbruik.
        RateLimiter::for('connect-device', function (Request $request) {
            $route = $request->route()?->uri() ?? $request->path();
            $credential = (string) ($request->input('device_binding') ?: $request->input('device_id') ?: '');
            $client = $credential !== ''
                ? 'device:'.hash('sha256', $credential)
                : \App\Support\ClientKey::forGuest($request);

            return [
                Limit::perMinute(30)->by($route.'|'.$client),
                Limit::perMinute(30 * \App\Support\ClientKey::natFactor())->by($route.'|ip:'.$request->ip()),
            ];
        });

        // Broadcastfouten (Reverb onbereikbaar) nooit laten doorwerken in de
        // request: zie ResilientPusherBroadcaster.
        $this->app->make(BroadcastManager::class)->extend('reverb', function ($app, array $config) {
            return new ResilientPusherBroadcaster(
                $app->make(BroadcastManager::class)->pusher($config)
            );
        });
    }
}
