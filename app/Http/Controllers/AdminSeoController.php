<?php

namespace App\Http\Controllers;

use App\Models\SeoOverride;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * SEO-CMS (admin). Beheert per milmap.nl-pagina × taal de SEO-velden. De
 * "Genereer met Claude"-knop stelt titel/description voor via de Anthropic API.
 *
 * Auth: admin.auth-middleware (zie routes/api.php).
 */
class AdminSeoController extends Controller
{
    /** GET /api/v1/admin/seo — registry + talen + bestaande overrides. */
    public function index()
    {
        return response()->json([
            'pages'     => config('seo_pages.pages', []),
            'locales'   => config('seo_pages.locales', ['nl']),
            'ai_ready'  => (bool) config('services.anthropic.key'),
            'overrides' => SeoOverride::all()->map(fn ($o) => $this->present($o))->values(),
        ]);
    }

    /** POST /api/v1/admin/seo — upsert één pagina+taal. */
    public function upsert(Request $request)
    {
        $data = $request->validate([
            'page_key'       => ['required', 'string', Rule::in($this->pageKeys())],
            'locale'         => ['required', 'string', Rule::in(config('seo_pages.locales', ['nl']))],
            'title'          => ['nullable', 'string', 'max:255'],
            'description'    => ['nullable', 'string', 'max:1000'],
            'og_title'       => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string', 'max:1000'],
            'og_image'       => ['nullable', 'string', 'max:2048'],
            'canonical'      => ['nullable', 'string', 'max:2048'],
            'robots'         => ['nullable', 'string', 'max:64'],
        ]);

        $override = SeoOverride::updateOrCreate(
            ['page_key' => $data['page_key'], 'locale' => $data['locale']],
            array_merge(
                array_intersect_key($data, array_flip(SeoOverride::FIELDS)),
                ['updated_by' => Auth::id()],
            ),
        );

        return response()->json(['ok' => true, 'override' => $this->present($override)]);
    }

    /**
     * POST /api/v1/admin/seo/generate — Claude stelt SEO voor één pagina+taal voor.
     * Vereist ANTHROPIC_API_KEY; faalt anders met 422.
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'page_key' => ['required', 'string', Rule::in($this->pageKeys())],
            'locale'   => ['required', 'string', Rule::in(config('seo_pages.locales', ['nl']))],
            'context'  => ['nullable', 'string', 'max:2000'],
        ]);

        $apiKey = config('services.anthropic.key');
        if (! $apiKey) {
            return response()->json([
                'ok'      => false,
                'message' => 'Claude is niet geconfigureerd: zet ANTHROPIC_API_KEY in de backend-.env.',
            ], 422);
        }

        $page = collect(config('seo_pages.pages', []))->firstWhere('key', $data['page_key']);
        $localeName = [
            'nl' => 'Nederlands', 'en' => 'English', 'de' => 'Deutsch', 'es' => 'Español',
        ][$data['locale']] ?? $data['locale'];

        $prompt = "Je bent een SEO-specialist voor MilMap — professionele software voor "
            . "routeplanning, militaire kaarten (MGRS), real-time navigatie en operationele "
            . "documentatie, gebruikt door militairen, SAR-teams en expeditie-planners.\n\n"
            . "Schrijf SEO-metadata voor de pagina \"{$page['label']}\" (pad {$page['path']}) "
            . "in het {$localeName}.\n"
            . ($data['context'] ? "Extra context: {$data['context']}\n" : '')
            . "\nEisen:\n"
            . "- title: pakkende meta-title, maximaal ~60 tekens, eindig met \" — MilMap\".\n"
            . "- description: wervende meta-description, 140–155 tekens, met een call-to-action.\n"
            . "- og_title / og_description: social-share-varianten (mogen iets korter/pakkender).\n"
            . "- Schrijf volledig in het {$localeName}. Geen aanhalingstekens rond de teksten.";

        $schema = [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['title', 'description', 'og_title', 'og_description'],
            'properties'           => [
                'title'          => ['type' => 'string'],
                'description'    => ['type' => 'string'],
                'og_title'       => ['type' => 'string'],
                'og_description' => ['type' => 'string'],
            ],
        ];

        try {
            $resp = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model'         => config('services.anthropic.model', 'claude-opus-4-8'),
                'max_tokens'    => 1024,
                'output_config' => [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                'messages'      => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Claude-aanroep mislukt: ' . $e->getMessage()], 502);
        }

        if (! $resp->successful()) {
            $msg = $resp->json('error.message') ?? ('HTTP ' . $resp->status());
            return response()->json(['ok' => false, 'message' => 'Claude-fout: ' . $msg], 502);
        }

        // Tekst uit het eerste text-block halen en als JSON parsen.
        $text = collect($resp->json('content', []))
            ->firstWhere('type', 'text')['text'] ?? '';
        $suggestion = json_decode($text, true);

        if (! is_array($suggestion)) {
            return response()->json(['ok' => false, 'message' => 'Kon het Claude-antwoord niet lezen.'], 502);
        }

        return response()->json(['ok' => true, 'suggestion' => $suggestion]);
    }

    /**
     * POST /api/v1/admin/seo/upload-image — upload een OG-share-afbeelding.
     * Slaat op de public-disk op en geeft de absolute URL terug (voor og_image).
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $path = $request->file('image')->store('seo', 'public');

        return response()->json([
            'ok'   => true,
            'url'  => Storage::disk('public')->url($path),
            'path' => $path,
        ]);
    }

    private function pageKeys(): array
    {
        return collect(config('seo_pages.pages', []))->pluck('key')->all();
    }

    private function present(SeoOverride $o): array
    {
        return [
            'page_key'       => $o->page_key,
            'locale'         => $o->locale,
            'title'          => $o->title,
            'description'    => $o->description,
            'og_title'       => $o->og_title,
            'og_description' => $o->og_description,
            'og_image'       => $o->og_image,
            'canonical'      => $o->canonical,
            'robots'         => $o->robots,
            'updated_at'     => optional($o->updated_at)->toIso8601String(),
        ];
    }
}
