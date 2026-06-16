<?php

namespace App\Http\Controllers;

use App\Models\MailSend;

/**
 * Open-tracking via een onzichtbare 1×1-pixel die in elke campagne-mail staat. Wordt de
 * pixel geladen, dan registreren we de opening op de bijbehorende mail_sends-rij.
 *
 * NB: open-tracking is altijd een schatting — mailclients die afbeeldingen blokkeren
 * tellen als "niet geopend", en sommige (Apple Mail Privacy) prefetchen de pixel. We
 * geven daarom ALTIJD een geldige gif terug (ook bij een onbekende token), zodat de
 * mail nooit een kapot plaatje toont en de route geen geldige/ongeldige tokens lekt.
 */
class MailTrackingController extends Controller
{
    // 1×1 transparante GIF (43 bytes).
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function pixel(string $token)
    {
        $send = MailSend::where('token', $token)->first();
        if ($send) {
            $send->forceFill([
                'opened_at'  => $send->opened_at ?? now(), // eerste opening telt
                'open_count' => $send->open_count + 1,
            ])->save();
        }

        $gif = base64_decode(self::PIXEL);

        return response($gif, 200, [
            'Content-Type'   => 'image/gif',
            'Content-Length' => (string) strlen($gif),
            'Cache-Control'  => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'         => 'no-cache',
        ]);
    }
}
