<?php

namespace Database\Seeders;

use App\Models\LegalDocument;
use Illuminate\Database\Seeder;

/**
 * Seedt de juridische documenten voor legal.milmap.nl vanuit
 * database/data/legal-seed.json (gemigreerd uit de bestaande NL-content in
 * MilMap-Frontend/src/data/legalDocs.js en de EN-content in Milmap.nl i18n).
 *
 * Idempotent: updateOrCreate op (slug, locale). Draai met:
 *   php artisan db:seed --class=Database\\Seeders\\LegalDocumentSeeder
 */
class LegalDocumentSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/legal-seed.json');
        if (! is_file($path)) {
            $this->command?->warn("legal-seed.json niet gevonden op {$path} — niets geseed.");
            return;
        }

        $seed = json_decode(file_get_contents($path), true);
        $docs = $seed['documents'] ?? [];

        $slugs = collect($docs)->pluck('slug')->all();

        // Opschonen: documenten (slugs) die niet meer in de seed staan verwijderen
        // (bv. het oude 'cookies'). Zo blijft de set exact gelijk aan de bron.
        if ($slugs) {
            LegalDocument::whereNotIn('slug', $slugs)->delete();
        }

        foreach ($docs as $doc) {
            // Per document schoon herzetten: verwijder eventuele oude taalrijen
            // (bv. een stale EN-vertaling) en plaats de taalrijen uit de seed terug.
            LegalDocument::where('slug', $doc['slug'])->delete();

            foreach (($doc['translations'] ?? []) as $locale => $content) {
                if (! $content) {
                    continue;
                }
                LegalDocument::create([
                    'slug'           => $doc['slug'],
                    'locale'         => $locale,
                    'title'          => $content['title'] ?? null,
                    'intro'          => $content['intro'] ?? null,
                    'sections'       => $content['sections'] ?? [],
                    'effective_date' => $doc['effective_date'] ?? null,
                    'published'      => $doc['published'] ?? false,
                    'sort'           => $doc['sort'] ?? 0,
                ]);
            }
        }

        $this->command?->info('Legal-documenten geseed: ' . implode(', ', $slugs));
    }
}
