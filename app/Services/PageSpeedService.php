<?php

namespace App\Services;

use App\Models\PageSpeedReport;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Google PageSpeed Insights-koppeling. Vraagt de Lighthouse-analyse op voor een
 * lijst te monitoren URL's (mobiel + desktop) en bewaart elke meting in
 * pagespeed_reports zodat de admin trends ziet en de takengenerator op de
 * laatste scores kan reageren.
 *
 * De API werkt zonder sleutel bij laag volume; een API-sleutel (Setting
 * `pagespeed_api_key`, of env PAGESPEED_API_KEY) verhoogt de quota. De te
 * monitoren URL's staan in Setting `pagespeed_urls` en zijn in-app instelbaar.
 */
class PageSpeedService
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    private const STRATEGIES = ['mobile', 'desktop'];

    /** Standaard te monitoren pagina's als de admin er nog geen heeft ingesteld. */
    private const DEFAULT_URLS = [
        'https://milmap.nl/',
        'https://milmap.nl/app',
        'https://app.milmap.nl/',
    ];

    /** Optionele API-sleutel (Setting wint van env). */
    public function apiKey(): ?string
    {
        $key = Setting::get('pagespeed_api_key', '');
        return $key ?: (config('services.pagespeed.key') ?: null);
    }

    /** Lijst met te monitoren URL's. */
    public function urls(): array
    {
        $urls = Setting::get('pagespeed_urls', null);
        if (! is_array($urls) || empty($urls)) {
            return self::DEFAULT_URLS;
        }
        return array_values(array_filter(array_map('trim', $urls)));
    }

    public function saveUrls(array $urls): void
    {
        $clean = array_values(array_filter(array_map('trim', $urls), fn ($u) => $u !== ''));
        Setting::put('pagespeed_urls', $clean);
    }

    public function saveApiKey(string $key): void
    {
        Setting::put('pagespeed_api_key', trim($key));
    }

    /**
     * Meet alle URL's × strategieën en sla de resultaten op.
     *
     * @return array{measured:int,failed:int,reports:array}
     */
    public function run(): array
    {
        $measured = 0; $failed = 0; $reports = [];

        foreach ($this->urls() as $url) {
            foreach (self::STRATEGIES as $strategy) {
                $report = $this->measure($url, $strategy);
                $reports[] = $report->toApiArray();
                $report->error ? $failed++ : $measured++;
            }
        }

        return ['measured' => $measured, 'failed' => $failed, 'reports' => $reports];
    }

    /** Eén meting uitvoeren en opslaan. */
    public function measure(string $url, string $strategy): PageSpeedReport
    {
        $params = [
            'url'      => $url,
            'strategy' => $strategy,
            'category' => ['performance', 'accessibility', 'best-practices', 'seo'],
        ];
        if ($key = $this->apiKey()) {
            $params['key'] = $key;
        }

        try {
            $resp = Http::timeout(60)->get(self::ENDPOINT, $params);
            if (! $resp->successful()) {
                $msg = $resp->json('error.message') ?? "HTTP {$resp->status()}";
                return $this->store($url, $strategy, null, "PageSpeed-fout: {$msg}");
            }
            return $this->store($url, $strategy, $resp->json('lighthouseResult'), null);
        } catch (\Throwable $e) {
            return $this->store($url, $strategy, null, 'Onbereikbaar: ' . $e->getMessage());
        }
    }

    /** Lighthouse-payload → PageSpeedReport-rij. */
    private function store(string $url, string $strategy, ?array $lh, ?string $error): PageSpeedReport
    {
        if ($error !== null || ! is_array($lh)) {
            return PageSpeedReport::create([
                'url' => $url, 'strategy' => $strategy, 'error' => $error ?? 'Geen data',
            ]);
        }

        $cat = $lh['categories'] ?? [];
        $aud = $lh['audits'] ?? [];

        $score = fn (string $k) => isset($cat[$k]['score']) && $cat[$k]['score'] !== null
            ? (int) round($cat[$k]['score'] * 100)
            : null;
        $num = fn (string $k) => $aud[$k]['numericValue'] ?? null;

        return PageSpeedReport::create([
            'url'            => $url,
            'strategy'       => $strategy,
            'performance'    => $score('performance'),
            'accessibility'  => $score('accessibility'),
            'best_practices' => $score('best-practices'),
            'seo'            => $score('seo'),
            // Tijd-metrieken van ms → s waar dat leesbaarder is.
            'lcp'  => ($v = $num('largest-contentful-paint')) !== null ? round($v / 1000, 2) : null,
            'fcp'  => ($v = $num('first-contentful-paint')) !== null ? round($v / 1000, 2) : null,
            'cls'  => ($v = $num('cumulative-layout-shift')) !== null ? round($v, 3) : null,
            'tbt'  => ($v = $num('total-blocking-time')) !== null ? round($v) : null,
            'si'   => ($v = $num('speed-index')) !== null ? round($v / 1000, 2) : null,
            'ttfb' => ($v = $num('server-response-time')) !== null ? round($v) : null,
        ]);
    }

    /**
     * Laatste meting per URL × strategie (voor het overzicht).
     *
     * @return array<int, array>
     */
    public function latest(): array
    {
        $out = [];
        foreach ($this->urls() as $url) {
            foreach (self::STRATEGIES as $strategy) {
                $row = PageSpeedReport::where('url', $url)
                    ->where('strategy', $strategy)
                    ->latest()
                    ->first();
                if ($row) {
                    $out[] = $row->toApiArray();
                }
            }
        }
        return $out;
    }

    /** Tijdreeks (oplopend) van de performance-score voor één URL × strategie. */
    public function history(string $url, string $strategy, int $limit = 30): array
    {
        // Pak de laatste $limit metingen (nieuwste eerst), draai daarna om naar
        // chronologische volgorde (oud → nieuw) voor de grafiek.
        return PageSpeedReport::where('url', $url)
            ->where('strategy', $strategy)
            ->whereNull('error')
            ->latest()
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->map->toApiArray()
            ->values()
            ->all();
    }
}
