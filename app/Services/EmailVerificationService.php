<?php

namespace App\Services;

use App\Mail\VerifyEmail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/**
 * Bouwt de signed verificatie-URL en verstuurt de verificatiemail. Gedeeld door
 * de registratie (eerste keer) en de "opnieuw versturen"-knop op de wall.
 */
class EmailVerificationService
{
    /** Signed, 7 dagen geldige link naar GET /api/v1/email/verify/{id}/{hash}. */
    public static function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addDays(7),
            ['id' => $user->id, 'hash' => $user->emailVerificationHash()]
        );
    }

    /**
     * Verstuur de verificatiemail. Stil overslaan als al geverifieerd. Fouten
     * worden gelogd maar gooien niet door (mag registratie/login nooit breken).
     */
    public static function send(User $user): void
    {
        if ($user->hasVerifiedEmail() || $user->isArchived()) {
            return;
        }

        // Geen zin om te versturen naar een leeg/ongeldig adres: de mailserver
        // weigert dat met "550 No such recipient here" en het vult onze logs met
        // warnings. Stil overslaan (net als bij een al-geverifieerd account).
        if (! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('[verify] verificatiemail overgeslagen: ongeldig e-mailadres voor user ' . $user->id);

            return;
        }

        try {
            // Passwordless account (registratie met alleen e-mail): geef meteen
            // een link mee om alsnog een wachtwoord in te stellen. Optioneel —
            // inloggen kan ook zonder, via een code per mail.
            $setPasswordUrl = $user->password === null
                ? self::setPasswordUrl($user)
                : null;

            Mail::to($user->email)->send(
                new VerifyEmail($user, self::verificationUrl($user), $setPasswordUrl)
            );
        } catch (\Throwable $e) {
            Log::warning('[verify] kon verificatiemail niet versturen: ' . $e->getMessage());
        }
    }

    /** Zelfde mechanisme als een reguliere wachtwoord-reset (Password::createToken). */
    protected static function setPasswordUrl(User $user): string
    {
        $token = Password::createToken($user);
        $appUrl = config('app.app_url', 'https://app.milmap.nl');

        return "{$appUrl}/password-reset?token={$token}&email=" . urlencode($user->email);
    }
}
