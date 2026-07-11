<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;

/**
 * Publieke juridische documenten voor legal.milmap.nl. De Nuxt-site haalt dit
 * bij build/SSR op en rendert de hub + documentpagina's. Geen auth; alleen
 * GEPUBLICEERDE documenten worden teruggegeven.
 */
class LegalController extends Controller
{
    /**
     * GET /api/v1/legal — hub-overzicht.
     * { data: { "<slug>": { slug, effective_date, sort, locales: { nl: { title }, en: { title } } } } }
     * (alleen titels, geen bodies — voor de kaartjes op de hub).
     */
    public function index()
    {
        $out = [];

        foreach (LegalDocument::where('published', true)->orderBy('sort')->orderBy('slug')->get() as $d) {
            if (! isset($out[$d->slug])) {
                $out[$d->slug] = [
                    'slug'           => $d->slug,
                    'effective_date' => optional($d->effective_date)->toDateString(),
                    'sort'           => $d->sort,
                    'locales'        => [],
                ];
            }
            if ($d->title !== null && $d->title !== '') {
                $out[$d->slug]['locales'][$d->locale] = ['title' => $d->title];
            }
        }

        // Documenten zonder enige titel-in-taal weglaten (niets te tonen).
        $out = array_filter($out, fn ($doc) => ! empty($doc['locales']));

        return response()->json(['data' => $out])
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=86400');
    }

    /**
     * GET /api/v1/legal/{slug} — één document met volledige inhoud per taal.
     * { data: { slug, effective_date, sort, locales: { nl: { title, intro, sections }, en: {...} } } }
     */
    public function show(string $slug)
    {
        $docs = LegalDocument::where('slug', $slug)->where('published', true)->get();

        abort_if($docs->isEmpty(), 404, 'Legal document not found');

        $first = $docs->first();
        $data = [
            'slug'           => $slug,
            'effective_date' => optional($first->effective_date)->toDateString(),
            'sort'           => $first->sort,
            'locales'        => [],
        ];

        foreach ($docs as $d) {
            $data['locales'][$d->locale] = [
                'title'    => $d->title,
                'intro'    => $d->intro,
                'sections' => is_array($d->sections) ? array_values($d->sections) : [],
            ];
        }

        return response()->json(['data' => $data])
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=86400');
    }
}
