<?php

namespace App\Services;

use App\Models\MailCustomTemplate;
use Illuminate\Support\Facades\View;

/**
 * Leest de campagne-template-registry en lost per template de juiste
 * gelokaliseerde view + onderwerp op, met harde fallback naar de standaardtaal.
 * Eén plek hergebruikt door preview, testmail, verzendjob en follow-ups.
 *
 * Twee bronnen, ZELFDE vorm:
 *  - code-templates uit config/mail_templates.php (Blade-bestanden);
 *  - door de admin opgemaakte templates uit mail_custom_templates (blok-JSON),
 *    die via de gedeelde `emails.custom`-view renderen.
 */
class MailTemplateRegistry
{
    /** Per-request cache van de gemergede registry (voorkomt DB-hit per ontvanger). */
    private static ?array $cache = null;

    /** @return array<string,array> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $file = config('mail_templates', []);

        $custom = [];
        try {
            foreach (MailCustomTemplate::all() as $tpl) {
                // Code-templates winnen bij een (onwaarschijnlijke) sleutelbotsing.
                if (! array_key_exists($tpl->key, $file)) {
                    $custom[$tpl->key] = $tpl->toRegistryEntry();
                }
            }
        } catch (\Throwable $e) {
            // Tabel bestaat nog niet (bv. vóór migratie) → alleen code-templates.
        }

        return self::$cache = array_merge($file, $custom);
    }

    /** Leeg de per-request cache (na aanmaken/wijzigen van een custom template). */
    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function get(string $key): ?array
    {
        return static::all()[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, static::all());
    }

    /**
     * Resolve view + subject + daadwerkelijke taal (+ optionele view-data) voor
     * een template-key.
     *
     * @return array{view:string,locale:string,subject:string,data:array}|null
     */
    public static function resolve(string $key, ?string $locale, string $defaultLocale = 'nl'): ?array
    {
        $tpl = static::get($key);
        if (! $tpl) {
            return null;
        }

        $locale  = $locale ?: $defaultLocale;
        $locales = $tpl['locales'] ?? [$defaultLocale];

        // Gevraagde taal als die bestaat, anders de standaardtaal, anders de eerste.
        $useLocale = in_array($locale, $locales, true)
            ? $locale
            : (in_array($defaultLocale, $locales, true) ? $defaultLocale : ($locales[0] ?? $defaultLocale));

        // ── Custom (blok-)template: gedeelde emails.custom-view + blocks-data ──
        if (! empty($tpl['custom'])) {
            $blocks = $tpl['blocks'][$useLocale]
                ?? $tpl['blocks'][$defaultLocale]
                ?? (is_array($tpl['blocks'] ?? null) ? (reset($tpl['blocks']) ?: []) : []);
            $subject = $tpl['subjects'][$useLocale]
                ?? $tpl['subjects'][$defaultLocale]
                ?? ($tpl['label'] ?? 'MilMap');

            return [
                'view'    => 'emails.custom',
                'locale'  => $useLocale,
                'subject' => $subject,
                'data'    => [
                    'blocks' => is_array($blocks) ? $blocks : [],
                    'title'  => $tpl['label'] ?? 'MilMap',
                ],
            ];
        }

        // ── Code-template: gelokaliseerde Blade met bestands-fallback ──
        $view = $tpl['view'] . '.' . $useLocale;
        if (! View::exists($view)) {
            $useLocale = $defaultLocale;
            $view = $tpl['view'] . '.' . $defaultLocale;
        }

        $subject = $tpl['subjects'][$useLocale]
            ?? $tpl['subjects'][$defaultLocale]
            ?? ($tpl['label'] ?? 'MilMap');

        return ['view' => $view, 'locale' => $useLocale, 'subject' => $subject, 'data' => []];
    }
}
