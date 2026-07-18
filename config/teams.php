<?php

/*
|--------------------------------------------------------------------------
| Teams / seats
|--------------------------------------------------------------------------
| Instellingen voor het teamcentrum. Een team-abonnement bevat standaard
| `included_seats` seats; elke seat daarbóven wordt via de Stripe-meter-prijs
| `team_extra_seat` (zie config/billing.php + admin billing-mapping) afgerekend
| tegen `extra_seat_price_eur` per maand (jaar = ×12 vooruit).
|
| De "owner" van het abonnement telt zelf ook als seat.
*/

return [

    // Inbegrepen seats bij een team-abonnement (incl. de owner).
    'included_seats' => (int) env('TEAM_INCLUDED_SEATS', 10),

    // Prijs per extra seat per maand (alleen voor weergave/berekening in de UI;
    // de daadwerkelijke afrekening loopt via de Stripe-prijs team_extra_seat).
    'extra_seat_price_eur' => (float) env('TEAM_EXTRA_SEAT_PRICE_EUR', 2.0),

    // Rollen die een teamlid kan hebben.
    'roles' => ['member', 'guest'],

];
