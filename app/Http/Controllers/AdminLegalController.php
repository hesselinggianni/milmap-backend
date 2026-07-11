<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Legal-CMS (admin) — beheert juridische documenten per slug × taal voor
 * legal.milmap.nl. Eén document wordt in z'n geheel opgeslagen: document-brede
 * velden (slug, effective_date, published, sort) + een `translations`-map met
 * per taal { title, intro, sections }.
 *
 * Auth: admin.auth-middleware (zie routes/api.php).
 */
class AdminLegalController extends Controller
{
    /** GET /api/v1/admin/legal — talen + alle documenten (incl. concepten), gegroepeerd per slug. */
    public function index()
    {
        $grouped = LegalDocument::orderBy('sort')->orderBy('slug')->get()
            ->groupBy('slug')
            ->map(fn ($rows) => $this->present($rows))
            ->values();

        return response()->json([
            'locales'        => config('legal.locales', ['nl', 'en']),
            'default_locale' => config('legal.default_locale', 'nl'),
            'documents'      => $grouped,
        ]);
    }

    /** GET /api/v1/admin/legal/{slug} — één document, gegroepeerd. */
    public function show(string $slug)
    {
        $rows = LegalDocument::where('slug', $slug)->get();
        abort_if($rows->isEmpty(), 404, 'Legal document not found');

        return response()->json(['document' => $this->present($rows)]);
    }

    /**
     * POST /api/v1/admin/legal — heel document upserten (alle talen tegelijk).
     * Body: { slug, effective_date?, published?, sort?, translations: { nl: {title,intro,sections}, en: {...} } }
     */
    public function upsert(Request $request)
    {
        $locales = config('legal.locales', ['nl', 'en']);

        $data = $request->validate([
            'slug'                        => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'effective_date'             => ['nullable', 'date'],
            'published'                  => ['boolean'],
            'sort'                       => ['integer'],
            'translations'              => ['required', 'array'],
            'translations.*.title'      => ['nullable', 'string', 'max:255'],
            'translations.*.intro'      => ['nullable', 'string', 'max:5000'],
            'translations.*.sections'   => ['nullable', 'array'],
            'translations.*.sections.*.heading' => ['nullable', 'string', 'max:255'],
            'translations.*.sections.*.body'    => ['nullable', 'string'],
        ]);

        // Alleen bekende talen accepteren.
        $translations = array_intersect_key($data['translations'], array_flip($locales));
        abort_if(empty($translations), 422, 'Geen geldige taal in translations.');

        $docFields = [
            'effective_date' => $data['effective_date'] ?? null,
            'published'      => $data['published'] ?? false,
            'sort'           => $data['sort'] ?? 0,
            'updated_by'     => Auth::id(),
        ];

        DB::transaction(function () use ($data, $translations, $docFields) {
            foreach ($translations as $locale => $content) {
                LegalDocument::updateOrCreate(
                    ['slug' => $data['slug'], 'locale' => $locale],
                    array_merge($docFields, [
                        'title'    => $content['title'] ?? null,
                        'intro'    => $content['intro'] ?? null,
                        'sections' => $this->cleanSections($content['sections'] ?? []),
                    ]),
                );
            }
        });

        $rows = LegalDocument::where('slug', $data['slug'])->get();

        return response()->json(['ok' => true, 'document' => $this->present($rows)]);
    }

    /**
     * POST /api/v1/admin/legal/reorder — volgorde van documenten.
     * Body: { order: ["privacy", "terms", ...] } (slugs) — zet `sort` per slug over álle taalrijen.
     */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['string', 'max:64'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['order'] as $i => $slug) {
                LegalDocument::where('slug', $slug)->update(['sort' => $i]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /** DELETE /api/v1/admin/legal/{slug} — verwijder het hele document (alle talen). */
    public function destroy(string $slug)
    {
        $deleted = LegalDocument::where('slug', $slug)->delete();
        abort_if($deleted === 0, 404, 'Legal document not found');

        return response()->json(['ok' => true]);
    }

    /** Normaliseer secties: gooi lege secties weg, behoud alleen heading/body. */
    private function cleanSections($sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($s) {
            $heading = is_array($s) ? trim((string) ($s['heading'] ?? '')) : '';
            $body    = is_array($s) ? (string) ($s['body'] ?? '') : '';
            if ($heading === '' && trim($body) === '') {
                return null;
            }
            return ['heading' => $heading, 'body' => $body];
        }, $sections)));
    }

    /** Groepeer de taalrijen van één slug tot één admin-document. */
    private function present($rows): array
    {
        $first = $rows->first();

        $translations = [];
        foreach ($rows as $r) {
            $translations[$r->locale] = [
                'title'    => $r->title,
                'intro'    => $r->intro,
                'sections' => is_array($r->sections) ? array_values($r->sections) : [],
            ];
        }

        return [
            'slug'           => $first->slug,
            'effective_date' => optional($first->effective_date)->toDateString(),
            'published'      => (bool) $first->published,
            'sort'           => (int) $first->sort,
            'translations'   => $translations,
            'updated_at'     => optional($rows->max('updated_at'))->toIso8601String(),
        ];
    }
}
