<?php

namespace App\Support;

use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Instrumenteert élke uitgaande HTML-mail voor het universele verzendlog
 * (sent_emails): een open-pixel en klik-tracking op alle links, plus
 * UTM-attributie zodat in de app-analytics te zien is dat een bezoeker uit
 * een specifieke mail kwam.
 *
 * Aangeroepen vanuit de MessageSending-listener in AppServiceProvider:
 *  - genereert een token en zet die als X-MilMap-Track header op het bericht
 *    (zodat de MessageSent-listener de log-rijen kan terugvinden);
 *  - herschrijft elke <a href="http…"> naar {api}/m/c/{token}?u=…&l=…&s=…
 *    (s = HMAC zodat het endpoint geen open redirect is), waarbij links naar
 *    eigen milmap.nl-domeinen eerst UTM-tags krijgen
 *    (utm_source=mail & utm_campaign=mail-<onderwerp-slug>);
 *  - injecteert een 1×1-pixel {api}/m/o2/{token}.gif.
 *
 * Uitzonderingen: afmeld-/tracking-URL's (/m/u/, /m/o/, /m/c/) en mailto's
 * blijven ongemoeid; campagnemails hebben daarnaast hun eigen mail_sends-
 * tracking — dubbel meten is daar onschadelijk.
 */
class SentMailTracker
{
    /** Herschrijf het bericht in-place; geeft het tracking-token terug. */
    public static function prepare(Email $message): string
    {
        $token = Str::random(32);
        $message->getHeaders()->addTextHeader('X-MilMap-Track', $token);

        $html = $message->getHtmlBody();
        if (! is_string($html) || $html === '') {
            return $token; // tekst-mail: wel loggen, niets te herschrijven
        }

        $base     = rtrim(config('app.url') ?: 'http://localhost:8000', '/') . '/api/v1';
        $campaign = 'mail-' . (Str::slug(mb_substr((string) $message->getSubject(), 0, 48)) ?: 'onbekend');

        // Links herschrijven. Mails zijn eigen templates, dus een anchor-regex
        // is hier betrouwbaar genoeg (geen geneste anchors in onze mails).
        $html = preg_replace_callback(
            '/(<a\b[^>]*\bhref=")([^"]+)("[^>]*>)(.*?)(<\/a>)/is',
            function ($m) use ($base, $token, $campaign) {
                [$all, $pre, $url, $mid, $inner, $post] = $m;

                if (! preg_match('#^https?://#i', $url)) {
                    return $all; // mailto:, tel:, ankers → laten staan
                }
                if (preg_match('#/m/(u|o|o2|c)/#', $url)) {
                    return $all; // eigen afmeld-/tracking-URL's nooit wrappen
                }

                // Eigen domeinen: UTM-attributie zodat analytics "Herkomst:
                // mail / mail-<onderwerp>" toont — vóór het wrappen, zodat de
                // tags op de bestemming landen.
                if (preg_match('#^https?://([a-z0-9-]+\.)*milmap\.nl#i', $url) && ! str_contains($url, 'utm_source=')) {
                    $url .= (str_contains($url, '?') ? '&' : '?')
                        . 'utm_source=mail&utm_medium=email&utm_campaign=' . rawurlencode($campaign);
                }

                // Linktekst als leesbaar label ("welke knop"), zonder HTML.
                $label = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($inner))), 0, 120);

                $wrapped = $base . '/m/c/' . $token
                    . '?u=' . rawurlencode($url)
                    . '&l=' . rawurlencode($label)
                    . '&s=' . self::sign($token, $url);

                return $pre . $wrapped . $mid . $inner . $post;
            },
            $html
        ) ?? $html;

        // Open-pixel — vlak voor </body>, anders achteraan.
        $pixel = '<img src="' . $base . '/m/o2/' . $token . '.gif" width="1" height="1" alt="" style="display:none;max-width:1px;max-height:1px;" />';
        $html  = str_contains($html, '</body>')
            ? str_replace('</body>', $pixel . '</body>', $html)
            : $html . $pixel;

        $message->html($html);

        return $token;
    }

    /**
     * HMAC over token+doel-URL: het klik-endpoint redirect alleen naar URL's
     * die wij zelf in een mail hebben gezet — geen open redirect.
     */
    public static function sign(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', $token . '|' . $url, (string) config('app.key')), 0, 16);
    }
}
