<?php

namespace App\Http\Controllers;

use App\Models\SiteEvent;
use App\Support\GeoIp;
use Illuminate\Http\Request;

/**
 * First-party analytics-endpoint voor milmap.nl.
 *
 * Privacy-uitgangspunten (bewust, defensie-doelgroep):
 * - geen cookies, geen fingerprinting-libs;
 * - IP wordt NIET opgeslagen — alleen sha256(dag-salt + ip + user-agent)
 *   zodat unieke bezoekers per dag telbaar zijn (Plausible-aanpak);
 * - alleen een vaste whitelist van event-namen wordt geaccepteerd.
 */
class SiteEventController extends Controller
{
    /** Toegestane events — houd deze lijst in sync met de site-plugin. */
    private const EVENTS = [
        'pageview',
        'cta_click',        // klik naar app.milmap.nl (register/login/checkout)
        'mgrs_convert',     // converter gebruikt (tab in meta)
        'mgrs_copy',        // resultaat gekopieerd
        'map_lookup',       // minimap: punt opgezocht (klik of zoek)
        'gate_shown',       // minimap: login-gate getoond
        'gate_cta_click',   // minimap: gate-CTA aangeklikt
        'lead_submit',      // e-mail lead-capture (fase 1)
        'exit_shown',       // exit-intent popup getoond (bv. pricing-korting)
    ];

    public function store(Request $request): \Illuminate\Http\Response
    {
        // Bot-/crawler-verkeer (Googlebot, Lighthouse/PageSpeed-cron,
        // linkpreviews, monitoring) telt niet mee: stil accepteren zonder iets
        // op te slaan, zodat bots geen oracle hebben.
        if (\App\Support\BotDetector::isBot($request->userAgent())) {
            return response()->noContent();
        }

        $data = $request->validate([
            'event'        => 'required|string|max:40',
            'path'         => 'required|string|max:300',
            'referrer'     => 'nullable|string|max:300',
            'locale'       => 'nullable|string|max:5',
            'utm_source'   => 'nullable|string|max:100',
            'utm_medium'   => 'nullable|string|max:100',
            'utm_campaign' => 'nullable|string|max:100',
            'meta'         => 'nullable|array',
        ]);

        if (! in_array($data['event'], self::EVENTS, true)) {
            return response()->noContent(); // stil negeren, geen oracle voor bots
        }

        $ua = (string) $request->userAgent();

        SiteEvent::create([
            'event'        => $data['event'],
            // Querystring én trailing slash weg: `/en/features/` en
            // `/en/features` zijn dezelfde pagina en moeten samen tellen.
            'path'         => rtrim((string) strtok($data['path'], '?'), '/') ?: '/',
            'referrer'     => $data['referrer'] ?? null,
            'locale'       => $data['locale'] ?? null,
            'utm_source'   => $data['utm_source'] ?? null,
            'utm_medium'   => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'visitor_hash' => hash('sha256', now()->toDateString().config('app.key').$request->ip().$ua),
            'device'       => preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : 'desktop',
            'country'      => GeoIp::country($request),
            'meta'         => isset($data['meta']) ? array_slice($data['meta'], 0, 10) : null,
            'occurred_at'  => now(),
        ]);

        return response()->noContent();
    }
}
