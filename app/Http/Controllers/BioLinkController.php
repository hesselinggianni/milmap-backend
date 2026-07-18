<?php

namespace App\Http\Controllers;

use App\Models\BioLink;

/**
 * Publieke link-in-bio links voor milmap.nl/link-in-bio. De Nuxt-pagina haalt
 * dit client-side op. Geen auth; alleen INGESCHAKELDE links, op volgorde.
 */
class BioLinkController extends Controller
{
    /**
     * GET /api/v1/bio-links
     * { data: [ { href, icon, variant, translations: { nl:{title,sub}, ... } } ] }
     */
    public function index()
    {
        $links = BioLink::where('enabled', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (BioLink $l) => [
                'href'         => $l->href,
                'icon'         => $l->icon,
                'variant'      => $l->variant,
                'translations' => is_array($l->translations) ? $l->translations : [],
            ])
            ->values();

        return response()->json(['data' => $links])
            ->header('Cache-Control', 'public, max-age=120, stale-while-revalidate=86400');
    }
}
