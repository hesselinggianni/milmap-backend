<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Houdt niet-geverifieerde, onbetaalde accounts tegen ZODRA de 24-uurs
 * coulanceperiode na registratie voorbij is (User::isEmailVerificationWalled()).
 * De gebruiker krijgt een 403 met code `email_verification_required`; de frontend
 * toont daarop de verificatie-wall.
 *
 * Een paar routes blijven bewust bereikbaar zodat de gebruiker zich kan
 * ontworstelen: uitloggen, eigen profiel ophalen, de mail opnieuw laten sturen,
 * en alles rond betalen (een Stripe-betaling ontgrendelt het account alsnog).
 */
class EnsureEmailVerified
{
    /** Toegestane korte routepaden (zonder /api/v1 prefix) tijdens de wall. */
    protected array $allow = [
        'logout',
        'users/me',
        'email/verification-notification',
        'billing',
        // Afmelden/voorkeuren mag nooit een geverifieerd account vereisen (consent).
        'mail-preferences',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ($user->is_admin ?? false) || ! $user->isEmailVerificationWalled()) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        return response()->json([
            'message'       => 'Bevestig eerst je e-mailadres om je account te gebruiken.',
            'code'          => 'email_verification_required',
            'email'         => $user->email,
            'registered_at' => optional($user->created_at)->toIso8601String(),
            'verification'  => $user->verificationState(),
        ], 403);
    }

    protected function isAllowed(Request $request): bool
    {
        $path = preg_replace('#^api/v\d+/#', '', ltrim($request->path(), '/'));

        foreach ($this->allow as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
