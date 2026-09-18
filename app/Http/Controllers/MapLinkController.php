<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Verkorte kaartlinks (maps.app.goo.gl, goo.gl/maps, maps.apple.com/p/...)
 * uitklappen naar hun volledige URL, zodat de app er coordinaten uit kan halen.
 *
 * De app kan dit niet zelf: Google en Apple sturen geen CORS-headers mee op hun
 * redirects. We volgen de redirect hier en geven alleen de eind-URL terug --
 * nooit de body, zodat dit geen open proxy wordt.
 */
class MapLinkController extends Controller
{
    /** Hosts waarvan we een verkorte link mogen uitklappen. */
    private const ALLOWED_HOSTS = [
        'goo.gl',
        'maps.app.goo.gl',
        'maps.apple.com',
        'beta.maps.apple.com',
    ];

    /** Hosts waar een redirect op mag uitkomen. */
    private const ALLOWED_TARGET_SUFFIXES = [
        'google.com',
        'google.nl',
        'goo.gl',
        'apple.com',
    ];

    private const MAX_REDIRECTS = 5;

    public function resolve(Request $request)
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $url = trim($data['url']);

        if (!$this->isAllowed($url, self::ALLOWED_HOSTS)) {
            return response()->json(['message' => 'Unsupported link'], 422);
        }

        $current = $url;

        for ($i = 0; $i < self::MAX_REDIRECTS; $i++) {
            try {
                $response = Http::withHeaders([
                        // Zonder browser-UA geeft Google een kale interstitial
                        // zonder de plek-coordinaten in de URL.
                        'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) '
                            . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
                    ])
                    ->timeout(8)
                    ->withoutRedirecting()
                    ->get($current);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Could not resolve link'], 502);
            }

            $location = $response->header('Location');

            if (!$location) {
                // Geen redirect meer: dit is de eind-URL.
                return response()->json(['url' => $current]);
            }

            $next = $this->absolutize($location, $current);

            if (!$next || !$this->isAllowed($next, self::ALLOWED_TARGET_SUFFIXES, true)) {
                return response()->json(['message' => 'Unsupported redirect target'], 422);
            }

            $current = $next;
        }

        return response()->json(['url' => $current]);
    }

    /** Relatieve Location-header terugbrengen naar een absolute URL. */
    private function absolutize(string $location, string $base): ?string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $path = str_starts_with($location, '/') ? $location : '/' . $location;

        return $parts['scheme'] . '://' . $parts['host'] . $path;
    }

    /**
     * Alleen https naar een host uit de lijst. Zonder $matchSuffix moet de host
     * exact voorkomen; met $matchSuffix mag het ook een subdomein zijn.
     */
    private function isAllowed(string $url, array $hosts, bool $matchSuffix = false): bool
    {
        $parts = parse_url($url);

        if (!isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach ($hosts as $allowed) {
            if ($host === $allowed) {
                return true;
            }
            if ($matchSuffix && str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }
}
