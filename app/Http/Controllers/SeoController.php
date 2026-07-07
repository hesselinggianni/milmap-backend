<?php

namespace App\Http\Controllers;

use App\Models\SeoOverride;

/**
 * Publieke SEO-overrides voor milmap.nl. De Nuxt-site haalt dit bij build/SSR
 * op en overschrijft er de page-meta mee (zie composables/useSeoOverride).
 * Geen auth — bevat alleen publieke marketing-metadata.
 */
class SeoController extends Controller
{
    /** GET /api/v1/seo — { "<page_key>": { "<locale>": { title, description, ... } } } */
    public function index()
    {
        $out = [];

        foreach (SeoOverride::all() as $o) {
            $fields = array_filter([
                'title'         => $o->title,
                'description'   => $o->description,
                'ogTitle'       => $o->og_title,
                'ogDescription' => $o->og_description,
                'ogImage'       => $o->og_image,
                'canonical'     => $o->canonical,
                'robots'        => $o->robots,
            ], fn ($v) => $v !== null && $v !== '');

            if (! empty($fields)) {
                $out[$o->page_key][$o->locale] = $fields;
            }
        }

        return response()->json(['data' => $out])
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=86400');
    }
}
