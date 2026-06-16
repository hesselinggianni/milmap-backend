<?php

namespace App\Services;

use App\Mail\VerifyEmail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

        try {
            Mail::to($user->email)->send(
                new VerifyEmail($user, self::verificationUrl($user))
            );
        } catch (\Throwable $e) {
            Log::warning('[verify] kon verificatiemail niet versturen: ' . $e->getMessage());
        }
    }
}
