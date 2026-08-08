<?php

namespace App\Http\Controllers;

use App\Models\ShortcutTemplate;

/**
 * Publieke opdrachten-galerij voor de app (Meer → Siri & Opdrachten). Geen
 * auth: het zijn openbare iCloud-links, en de galerij moet ook te zien zijn
 * vóórdat iemand inlogt. Alleen INGESCHAKELDE items, op volgorde.
 */
class ShortcutTemplateController extends Controller
{
    /**
     * GET /api/v1/shortcut-templates
     * { data: [ { id, icloud_url, icon, category, translations } ] }
     */
    public function index()
    {
        $templates = ShortcutTemplate::where('enabled', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (ShortcutTemplate $t) => [
                'id'           => $t->id,
                'icloud_url'   => $t->icloud_url,
                'icon'         => $t->icon,
                'category'     => $t->category,
                'translations' => is_array($t->translations) ? $t->translations : [],
            ])
            ->values();

        return response()->json(['data' => $templates])
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=86400');
    }
}
