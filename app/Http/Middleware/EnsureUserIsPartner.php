<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate voor het partnerportaal (/partner/*): de ingelogde milmap-user moet een
 * partnerprofiel hebben. De status (pending/approved/suspended) wordt bewust
 * NIET hier afgedwongen — een pending partner moet zijn status kunnen zien en
 * het portaal toont daarvoor de "in behandeling"-pagina.
 */
class EnsureUserIsPartner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->partner) {
            return response()->json(['message' => 'Geen partneraccount gevonden.'], 403);
        }

        return $next($request);
    }
}
