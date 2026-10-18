<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Houdt handmatig geblokkeerde accounts (users.blocked_at, gezet vanuit het
 * admin-profiel) buiten de deur. Bij het blokkeren worden alle tokens al
 * ingetrokken, dus normaal komt een geblokkeerde gebruiker hier nooit met een
 * geldige sessie. Dit is het vangnet voor tokens die daarna nog ontstaan
 * (handoff, social login, Connect-apparaatlogin) zodat één vlag in de
 * database altijd leidend is.
 *
 * Antwoord: 403 met code `account_blocked`; de app logt daarop uit en toont
 * de melding op het inlogscherm. Alleen uitloggen blijft toegestaan.
 */
class EnsureNotBlocked
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ($user->is_admin ?? false) || ! $user->isBlocked()) {
            return $next($request);
        }

        $path = preg_replace('#^api/v\d+/#', '', ltrim($request->path(), '/'));
        if ($path === 'logout') {
            return $next($request);
        }

        return response()->json([
            'message'    => $user->blockedLoginMessage(),
            'code'       => 'account_blocked',
            'blocked_at' => optional($user->blocked_at)->toIso8601String(),
        ], 403);
    }
}
