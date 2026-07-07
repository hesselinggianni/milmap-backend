<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate premiumfuncties achter de gratis proefperiode of een betaald abonnement.
 *
 * Elk nieuw account krijgt 7 dagen volledige toegang (User::onAppTrial()). Is
 * de proef verlopen én is er geen actief abonnement, dan mag de gebruiker de
 * premiumfuncties niet meer MUTEREN (aanmaken/bewerken). Lezen blijft toegestaan
 * zodat een gratis gebruiker die als deelnemer/collaborator is uitgenodigd de
 * inhoud nog kan bekijken — de bijbehorende write-endpoints staan wél op slot.
 *
 * Blokkeert met 402 `subscription_required` zodat de frontend gericht een
 * upgrade-scherm kan tonen (onderscheiden van een 403 rechten-fout). Admins en
 * gebruikers met premium-toegang passeren zonder DB-hit voor de trial-check.
 *
 * Optionele parameter: de feature-sleutel (bv. `feature:missions`) die in de
 * 402-response meegaat zodat de frontend de juiste upgrade-context toont.
 */
class RequiresPremium
{
    public function handle(Request $request, Closure $next, ?string $feature = null): Response
    {
        $user = $request->user();

        // Geen user (auth-middleware vangt dit al), admins en premium-gebruikers
        // passeren altijd. onAppTrial()/subscribed() raakt de DB alleen wanneer
        // nodig.
        if (! $user || ($user->is_admin ?? false) || $user->hasPremiumAccess()) {
            return $next($request);
        }

        // Lezen verandert geen staat → altijd toegestaan (deelnemers blijven zien).
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Je gratis proefperiode is verlopen. Neem een abonnement om deze functie te blijven gebruiken.',
            'code'    => 'subscription_required',
            'feature' => $feature,
        ], 402);
    }
}
