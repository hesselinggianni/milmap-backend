<?php

namespace App\Http\Controllers;

use App\Models\ShortcutTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Beheer van de opdrachten-galerij (admin → "Opdrachten"). Elke rij is één
 * kant-en-klare iOS-opdracht met per-taal labels in `translations`.
 *
 * Auth: admin.auth-middleware (zie routes/api.php).
 */
class AdminShortcutTemplateController extends Controller
{
    /** Talen waarin de galerij wordt aangeboden — gelijk aan de app-talen die we redactioneel bijhouden. */
    private const LOCALES = ['nl', 'en', 'de', 'es', 'fr'];

    private const DEFAULT_LOCALE = 'nl';

    /** GET /api/v1/admin/shortcut-templates */
    public function index()
    {
        $templates = ShortcutTemplate::orderBy('sort')->orderBy('id')->get()
            ->map(fn ($t) => $this->present($t));

        return response()->json([
            'locales'        => self::LOCALES,
            'default_locale' => self::DEFAULT_LOCALE,
            'icons'          => ShortcutTemplate::ICONS,
            'categories'     => ShortcutTemplate::CATEGORIES,
            'templates'      => $templates->values(),
        ]);
    }

    /**
     * POST /api/v1/admin/shortcut-templates — aanmaken of bijwerken.
     * Body: { id?, icloud_url, icon, category, enabled?, translations: { nl:{title,sub,steps}, ... } }
     */
    public function upsert(Request $request)
    {
        $data = $request->validate([
            'id'                   => ['nullable', 'integer', 'exists:shortcut_templates,id'],
            // Alleen echte iCloud-deellinks: iets anders installeert niet en
            // levert alleen een doodlopende knop in de app op.
            'icloud_url'           => ['required', 'string', 'max:2048', 'url', 'regex:#^https://(www\.)?icloud\.com/shortcuts/#i'],
            'icon'                 => ['required', Rule::in(ShortcutTemplate::ICONS)],
            'category'             => ['required', Rule::in(ShortcutTemplate::CATEGORIES)],
            'enabled'              => ['boolean'],
            'translations'         => ['required', 'array'],
            'translations.*.title' => ['nullable', 'string', 'max:120'],
            'translations.*.sub'   => ['nullable', 'string', 'max:200'],
            // Korte uitleg van wat de opdracht doet / wat je nog moet invullen
            // (bv. "kies je WhatsApp-contact") — meerdere regels toegestaan.
            'translations.*.steps' => ['nullable', 'string', 'max:1000'],
        ], [
            'icloud_url.regex' => 'Dit moet een iCloud-deellink zijn (https://www.icloud.com/shortcuts/…). Deel de opdracht op je iPhone en plak de link die je dan krijgt.',
        ]);

        $translations = [];
        foreach (array_intersect_key($data['translations'], array_flip(self::LOCALES)) as $loc => $c) {
            $title = trim((string) ($c['title'] ?? ''));
            $sub   = trim((string) ($c['sub'] ?? ''));
            $steps = trim((string) ($c['steps'] ?? ''));
            if ($title === '' && $sub === '' && $steps === '') {
                continue;
            }
            $translations[$loc] = ['title' => $title, 'sub' => $sub, 'steps' => $steps];
        }

        abort_if(
            empty($translations[self::DEFAULT_LOCALE]['title'] ?? ''),
            422,
            'Titel in de standaardtaal (' . self::DEFAULT_LOCALE . ') is verplicht.'
        );

        $attrs = [
            'icloud_url'   => $data['icloud_url'],
            'icon'         => $data['icon'],
            'category'     => $data['category'],
            'enabled'      => $data['enabled'] ?? true,
            'translations' => $translations,
            'updated_by'   => Auth::id(),
        ];

        if (! empty($data['id'])) {
            $template = ShortcutTemplate::findOrFail($data['id']);
            $template->update($attrs);
        } else {
            $attrs['sort'] = (int) (ShortcutTemplate::max('sort') ?? 0) + 1;
            $template = ShortcutTemplate::create($attrs);
        }

        return response()->json(['ok' => true, 'template' => $this->present($template->refresh())]);
    }

    /** POST /api/v1/admin/shortcut-templates/reorder — { order: [id, ...] } */
    public function reorder(Request $request)
    {
        $data = $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['order'] as $i => $id) {
                ShortcutTemplate::where('id', $id)->update(['sort' => $i]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /** DELETE /api/v1/admin/shortcut-templates/{id} */
    public function destroy(int $id)
    {
        $deleted = ShortcutTemplate::where('id', $id)->delete();
        abort_if($deleted === 0, 404, 'Shortcut template not found');

        return response()->json(['ok' => true]);
    }

    private function present(ShortcutTemplate $t): array
    {
        return [
            'id'           => $t->id,
            'icloud_url'   => $t->icloud_url,
            'icon'         => $t->icon,
            'category'     => $t->category,
            'enabled'      => (bool) $t->enabled,
            'sort'         => (int) $t->sort,
            'translations' => is_array($t->translations) ? $t->translations : [],
            'updated_at'   => optional($t->updated_at)->toIso8601String(),
        ];
    }
}
