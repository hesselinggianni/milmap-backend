<?php

namespace App\Http\Controllers;

use App\Models\BioLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Link-in-bio-CMS (admin) — beheert de knoppen op milmap.nl/link-in-bio. Elke
 * link is één rij met per-taal labels (title/sub) in `translations`.
 *
 * Auth: admin.auth-middleware (zie routes/api.php).
 */
class AdminBioLinkController extends Controller
{
    /** GET /api/v1/admin/bio-links — talen + alle links (incl. uitgeschakelde). */
    public function index()
    {
        $links = BioLink::orderBy('sort')->orderBy('id')->get()->map(fn ($l) => $this->present($l));

        return response()->json([
            'locales'        => config('bio.locales', ['nl', 'en', 'de', 'es']),
            'default_locale' => config('bio.default_locale', 'nl'),
            'icons'          => BioLink::ICONS,
            'variants'       => BioLink::VARIANTS,
            'links'          => $links->values(),
        ]);
    }

    /**
     * POST /api/v1/admin/bio-links — link aanmaken of bijwerken.
     * Body: { id?, href, icon, variant, enabled?, translations: { nl:{title,sub}, ... } }
     */
    public function upsert(Request $request)
    {
        $locales = config('bio.locales', ['nl', 'en', 'de', 'es']);

        $data = $request->validate([
            'id'                       => ['nullable', 'integer', 'exists:bio_links,id'],
            'href'                     => ['required', 'string', 'max:2048', 'url'],
            'icon'                     => ['required', Rule::in(BioLink::ICONS)],
            'variant'                  => ['required', Rule::in(BioLink::VARIANTS)],
            'enabled'                  => ['boolean'],
            'translations'             => ['required', 'array'],
            'translations.*.title'     => ['nullable', 'string', 'max:120'],
            'translations.*.sub'       => ['nullable', 'string', 'max:160'],
        ]);

        // Alleen bekende talen bewaren; lege taalvelden weglaten.
        $translations = [];
        foreach (array_intersect_key($data['translations'], array_flip($locales)) as $loc => $c) {
            $title = trim((string) ($c['title'] ?? ''));
            $sub   = trim((string) ($c['sub'] ?? ''));
            if ($title === '' && $sub === '') {
                continue;
            }
            $translations[$loc] = ['title' => $title, 'sub' => $sub];
        }

        $default = config('bio.default_locale', 'nl');
        abort_if(empty($translations[$default]['title'] ?? ''), 422, "Titel in de standaardtaal ({$default}) is verplicht.");

        $attrs = [
            'href'         => $data['href'],
            'icon'         => $data['icon'],
            'variant'      => $data['variant'],
            'enabled'      => $data['enabled'] ?? true,
            'translations' => $translations,
            'updated_by'   => Auth::id(),
        ];

        if (! empty($data['id'])) {
            $link = BioLink::findOrFail($data['id']);
            $link->update($attrs);
        } else {
            $attrs['sort'] = (int) (BioLink::max('sort') ?? 0) + 1;
            $link = BioLink::create($attrs);
        }

        return response()->json(['ok' => true, 'link' => $this->present($link->refresh())]);
    }

    /**
     * POST /api/v1/admin/bio-links/reorder — volgorde.
     * Body: { order: [id, id, ...] }
     */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['order'] as $i => $id) {
                BioLink::where('id', $id)->update(['sort' => $i]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /** DELETE /api/v1/admin/bio-links/{id} */
    public function destroy(int $id)
    {
        $deleted = BioLink::where('id', $id)->delete();
        abort_if($deleted === 0, 404, 'Bio link not found');

        return response()->json(['ok' => true]);
    }

    /** Presenteer één link voor de admin. */
    private function present(BioLink $l): array
    {
        return [
            'id'           => $l->id,
            'href'         => $l->href,
            'icon'         => $l->icon,
            'variant'      => $l->variant,
            'enabled'      => (bool) $l->enabled,
            'sort'         => (int) $l->sort,
            'translations' => is_array($l->translations) ? $l->translations : [],
            'updated_at'   => optional($l->updated_at)->toIso8601String(),
        ];
    }
}
