<?php

namespace App\Services;

use App\Models\PageSpeedReport;
use App\Models\TaskLabel;
use App\Models\Todo;

/**
 * Zet lage PageSpeed-scores en slechte Core Web Vitals om in concrete taken die
 * Claude kan oppakken. Deterministisch met een stabiele dedupe_key per
 * URL × strategie × categorie, zodat herhaald draaien geen duplicaten geeft
 * (bestaande open taak wordt bijgewerkt met verse cijfers).
 */
class PageSpeedTaskService
{
    /** Statussen waarin een taak nog "open" is (niet opnieuw aanmaken). */
    private const OPEN = ['backlog', 'voorbereiding', 'wachtrij', 'bezig', 'review', 'feedback'];

    /** Onder deze drempel maken we een taak aan (Lighthouse-conventie: <90 = ruimte). */
    private const THRESHOLDS = [
        'performance'    => 80,
        'accessibility'  => 90,
        'best_practices' => 90,
        'seo'            => 90,
    ];

    private const LABELS = [
        'performance'    => 'prestaties',
        'accessibility'  => 'toegankelijkheid',
        'best_practices' => 'best practices',
        'seo'            => 'SEO',
    ];

    public function __construct(private PageSpeedService $ps) {}

    /**
     * Genereer taken uit de laatste meting per URL × strategie.
     *
     * @return array{created:int,updated:int,signals:int}
     */
    public function generate(): array
    {
        $label = TaskLabel::firstOrCreate(
            ['kind' => 'category', 'name' => 'PageSpeed'],
            ['color' => '#f59e0b']
        );

        $created = 0; $updated = 0; $signals = 0;

        foreach ($this->ps->urls() as $url) {
            foreach (['mobile', 'desktop'] as $strategy) {
                $report = PageSpeedReport::where('url', $url)
                    ->where('strategy', $strategy)
                    ->whereNull('error')
                    ->latest()
                    ->first();
                if (! $report) continue;

                foreach (self::THRESHOLDS as $cat => $min) {
                    $score = $report->{$cat};
                    if ($score === null || $score >= $min) continue;

                    $signals++;
                    $key = "pagespeed:{$cat}:{$strategy}:" . md5($url);
                    [$title, $desc] = $this->message($url, $strategy, $cat, $score, $report);

                    $open = Todo::where('dedupe_key', $key)->whereIn('status', self::OPEN)->first();
                    if ($open) {
                        $open->update(['title' => $title, 'description' => $desc]);
                        $updated++;
                        continue;
                    }

                    $todo = Todo::create([
                        'title'             => $title,
                        'description'       => $desc,
                        'repo'              => $this->repoFor($url),
                        'mode'              => 'fix',
                        'status'            => 'backlog',
                        'priority'          => $score < 50 ? 'hoog' : 'normaal',
                        'source'            => 'admin',
                        'dedupe_key'        => $key,
                        'status_changed_at' => now(),
                    ]);
                    $todo->labels()->syncWithoutDetaching([$label->id]);
                    $created++;
                }
            }
        }

        return ['created' => $created, 'updated' => $updated, 'signals' => $signals];
    }

    private function message(string $url, string $strategy, string $cat, int $score, PageSpeedReport $r): array
    {
        $catLabel = self::LABELS[$cat];
        $strat = $strategy === 'mobile' ? 'mobiel' : 'desktop';

        $title = "PageSpeed: {$catLabel} {$score}/100 op {$url} ({$strat})";

        $desc = "Google PageSpeed Insights meet een {$catLabel}-score van {$score}/100 voor {$url} op {$strat}. "
            . "Onderzoek de Lighthouse-audits en verbeter deze pagina zodat de score omhoog gaat.\n\n";

        if ($cat === 'performance') {
            $vitals = [];
            if ($r->lcp !== null)  $vitals[] = "LCP {$r->lcp}s";
            if ($r->cls !== null)  $vitals[] = "CLS {$r->cls}";
            if ($r->tbt !== null)  $vitals[] = "TBT {$r->tbt}ms";
            if ($r->fcp !== null)  $vitals[] = "FCP {$r->fcp}s";
            if ($r->si !== null)   $vitals[] = "Speed Index {$r->si}s";
            if ($r->ttfb !== null) $vitals[] = "TTFB {$r->ttfb}ms";
            if ($vitals) {
                $desc .= "Core Web Vitals: " . implode(' · ', $vitals) . ".\n\n";
            }
            $desc .= "Kijk naar: afbeeldingen optimaliseren/lazy-loaden, render-blokkerende JS/CSS uitstellen, "
                . "onnodige JavaScript verwijderen, caching/compressie, en fonts efficiënt laden.";
        } else {
            $desc .= "Open het volledige rapport in PageSpeed Insights voor de concrete aanbevelingen per audit.";
        }

        return [$title, $desc];
    }

    /** Grove repo-toewijzing op basis van de host. */
    private function repoFor(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        if (str_starts_with($host, 'app.')) return 'frontend';
        if (str_starts_with($host, 'admin.')) return 'admin';
        return 'site';
    }
}
