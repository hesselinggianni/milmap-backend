<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Wie is "de client" voor rate limiting, zonder alleen op het IP te leunen.
 *
 * Scholen, kazernes en bedrijven zitten met honderden tot duizenden gebruikers
 * achter één NAT-IP. Een limiet per IP sluit dan iedereen tegelijk buiten.
 * De app stuurt bij elk verzoek een willekeurige installatie-ID mee
 * (X-Milmap-Installation, zie MilMap-Frontend/src/services/installation.js);
 * <img>-tegels kunnen geen headers sturen en geven die als `_i`-queryparameter.
 *
 * Een installatie-ID is door de client te kiezen, dus nooit de enige grens:
 * naast de limiet per installatie geldt altijd een ruim plafond per IP
 * (limiet × natFactor), zodat roteren niet onbeperkt is. Brute-force op één
 * account wordt per account afgevangen (LoginController, LoginCodeController).
 */
class ClientKey
{
    public static function installation(Request $request): ?string
    {
        $id = $request->header('X-Milmap-Installation') ?: $request->query('_i');

        return is_string($id) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $id) ? strtolower($id) : null;
    }

    /** Installatie + IP als die er is, anders alleen het IP. */
    public static function forGuest(Request $request): string
    {
        $installation = self::installation($request);

        return $installation ? 'inst:'.sha1($installation).'|'.$request->ip() : 'ip:'.$request->ip();
    }

    /** Hoeveel installaties achter één IP we redelijk vinden (plafond = limiet × factor). */
    public static function natFactor(): int
    {
        return max(1, (int) config('services.throttle.nat_factor', 200));
    }
}
