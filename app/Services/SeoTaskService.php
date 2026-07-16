<?php

namespace App\Services;

use App\Models\TaskLabel;
use App\Models\Todo;

/**
 * Zet Google Search Console-signalen om in concrete SEO-taken die Claude kan
 * oppakken. Deterministisch met een stabiele dedupe_key per signaal, zodat
 * herhaald draaien geen duplicaten geeft (bestaande open taak wordt bijgewerkt).
 */
class SeoTaskService
{
    /** Statussen waarin een taak nog "open" is (niet opnieuw aanmaken). */
    private const OPEN = ['backlog', 'voorbereiding', 'wachtrij', 'bezig', 'review', 'feedback'];

    public function __construct(private GscService $gsc) {}

    public function generate(int $max = 15): array
    {
        if (! $this->gsc->isConfigured()) {
            return ['created' => 0, 'updated' => 0, 'error' => 'Search Console is nog niet geconfigureerd.'];
        }

        $signals = array_merge($this->ctrSignals(), $this->positionSignals());
        // Sorteer op impact (impressies) en cap.
        usort($signals, fn ($a, $b) => $b['impressions'] <=> $a['impressions']);
        $signals = array_slice($signals, 0, $max);

        $label = TaskLabel::firstOrCreate(['kind' => 'category', 'name' => 'SEO'], ['color' => '#16a34a']);

        $created = 0; $updated = 0;
        foreach ($signals as $sig) {
            $open = Todo::where('dedupe_key', $sig['key'])->whereIn('status', self::OPEN)->first();
            if ($open) {
                $open->update(['title' => $sig['title'], 'description' => $sig['desc']]);
                $updated++;
                continue;
            }
            $todo = Todo::create([
                'title'             => $sig['title'],
                'description'       => $sig['desc'],
                'repo'              => 'site',
                'mode'              => 'fix',
                'status'            => 'backlog',
                'priority'          => 'normaal',
                'source'            => 'admin',
                'dedupe_key'        => $sig['key'],
                'status_changed_at' => now(),
            ]);
            $todo->labels()->syncWithoutDetaching([$label->id]);
            $created++;
        }

        return ['created' => $created, 'updated' => $updated, 'signals' => count($signals)];
    }

    /** Pagina's met veel vertoningen maar lage CTR → title/meta verbeteren. */
    private function ctrSignals(): array
    {
        $out = [];
        foreach ($this->gsc->topRows('page', 28, 100) as $r) {
            if ($r['impressions'] >= 100 && $r['ctr'] < 0.02 && $r['key']) {
                $ctr = round($r['ctr'] * 100, 1);
                $out[] = [
                    'key'         => 'seo:ctr:' . md5($r['key']),
                    'impressions' => $r['impressions'],
                    'title'       => "SEO: verhoog CTR van {$r['key']} ({$ctr}% CTR, {$r['impressions']} vertoningen)",
                    'desc'        => "Deze pagina krijgt veel vertoningen in Google maar weinig kliks (CTR {$ctr}%). "
                        . "Verbeter de <title> en meta description zodat ze aantrekkelijker en relevanter zijn, "
                        . "voeg waar passend gestructureerde data toe, en zorg dat de intent van de zoeker matcht. "
                        . "Pagina: {$r['key']} (28 dagen, {$r['impressions']} vertoningen, positie ~" . round($r['position'], 1) . ").",
                ];
            }
        }
        return $out;
    }

    /** Zoekwoorden net buiten de top (positie 5–20) → content optimaliseren. */
    private function positionSignals(): array
    {
        $out = [];
        foreach ($this->gsc->topRows('query', 28, 150) as $r) {
            if ($r['position'] >= 5 && $r['position'] <= 20 && $r['impressions'] >= 50 && $r['key']) {
                $pos = round($r['position'], 1);
                $out[] = [
                    'key'         => 'seo:pos:' . md5($r['key']),
                    'impressions' => $r['impressions'],
                    'title'       => "SEO: optimaliseer voor \"{$r['key']}\" (positie ~{$pos}, {$r['impressions']} vertoningen)",
                    'desc'        => "Het zoekwoord \"{$r['key']}\" staat net buiten de topresultaten (gem. positie {$pos}) "
                        . "met {$r['impressions']} vertoningen in 28 dagen. Zoek de best passende pagina op milmap.nl "
                        . "(of maak/versterk er één), verwerk dit zoekwoord en gerelateerde termen natuurlijk in de "
                        . "content, koppen en interne links, en verbeter diepgang/actualiteit zodat de positie stijgt.",
                ];
            }
        }
        return $out;
    }
}
