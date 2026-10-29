<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Taal van de API-antwoorden (validatiemeldingen) volgt de voorkeurstaal van
 * de client: Nederlands alleen als dat bovenaan Accept-Language staat, anders
 * Engels. Een Duitser met nl als tweede taal krijgt dus geen Nederlands.
 *
 * De taal wordt altijd expliciet gezet, ook voor Engels: zo hangt het antwoord
 * niet af van APP_LOCALE op de server. (Productie gaf "validation.required"
 * terug, omdat de gekozen taal daar geen validatiebestand had.)
 *
 * Raakt bewust niets anders: mails zetten hun eigen taal via
 * User::preferredLocale() en push-teksten krijgen de taal expliciet mee.
 */
class SetApiLocale
{
    private const TALEN = ['nl'];
    private const STANDAARD = 'en';

    public function handle(Request $request, Closure $next): Response
    {
        $eerste = $request->getLanguages()[0] ?? '';
        $basis  = strtolower(substr(str_replace('_', '-', (string) $eerste), 0, 2));

        app()->setLocale(in_array($basis, self::TALEN, true) ? $basis : self::STANDAARD);

        return $next($request);
    }
}
