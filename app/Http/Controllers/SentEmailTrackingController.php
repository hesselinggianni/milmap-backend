<?php

namespace App\Http\Controllers;

use App\Models\SentEmail;
use App\Models\SentEmailClick;
use App\Support\SentMailTracker;
use Illuminate\Http\Request;

/**
 * Publieke tracking-endpoints voor het universele verzendlog (sent_emails) —
 * de pendant van MailTrackingController (dat alleen campagne-mail_sends dekt).
 *
 * - GET /m/o2/{token}.gif  → open-pixel. Altijd een geldige gif terug, ook bij
 *   een onbekende token (geen kapot plaatje, geen token-oracle).
 * - GET /m/c/{token}?u&l&s → klik-redirect. `s` is een HMAC over token+URL
 *   (zie SentMailTracker::sign) zodat dit géén open redirect is: alleen URL's
 *   die wij zelf in de mail zetten worden doorgestuurd; al het andere valt
 *   terug op milmap.nl.
 *
 * Een open of klik is meteen ook het hardste "aangekomen"-signaal dat we
 * zonder bounce-webhooks hebben.
 */
class SentEmailTrackingController extends Controller
{
    private const PIXEL    = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    private const FALLBACK = 'https://milmap.nl';

    public function pixel(string $token)
    {
        SentEmail::where('token', $token)->get()->each(function (SentEmail $m) {
            $m->forceFill([
                'opened_at'  => $m->opened_at ?? now(), // eerste opening telt
                'open_count' => $m->open_count + 1,
            ])->save();
        });

        $gif = base64_decode(self::PIXEL);

        return response($gif, 200, [
            'Content-Type'   => 'image/gif',
            'Content-Length' => (string) strlen($gif),
            'Cache-Control'  => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'         => 'no-cache',
        ]);
    }

    public function click(Request $request, string $token)
    {
        $url   = (string) $request->query('u', '');
        $sig   = (string) $request->query('s', '');
        $label = mb_substr(trim((string) $request->query('l', '')), 0, 120) ?: null;

        $validUrl = preg_match('#^https?://#i', $url)
            && hash_equals(SentMailTracker::sign($token, $url), $sig);

        if ($validUrl) {
            $mails = SentEmail::where('token', $token)->get();
            $now   = now();
            foreach ($mails as $m) {
                SentEmailClick::create([
                    'sent_email_id' => $m->id,
                    'url'           => mb_substr($url, 0, 500),
                    'label'         => $label,
                    'clicked_at'    => $now,
                ]);
                $m->forceFill([
                    // Een klik impliceert een opening — ook als de pixel
                    // geblokkeerd werd (veel clients laden geen afbeeldingen).
                    'opened_at'      => $m->opened_at ?? $now,
                    'click_count'    => $m->click_count + 1,
                    'last_click_url' => mb_substr($url, 0, 500),
                ])->save();
            }
        }

        return redirect()->away($validUrl ? $url : self::FALLBACK);
    }
}
