<?php

namespace App\Services;

use Illuminate\Support\Facades\View;

/**
 * Leest de campagne-template-registry (config/mail_templates.php) en lost per template
 * de juiste gelokaliseerde view + onderwerp op, met harde fallback naar de
 * standaardtaal. Eén plek hergebruikt door preview, testmail en de verzendjob.
 */
class MailTemplateRegistry
{
    /** @return array<string,array> */
    public static function all(): array
    {
        return config('mail_templates', []);
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
     * Resolve view + subject + daadwerkelijke taal voor een template-key.
     *
     * @return array{view:string,locale:string,subject:string}|null
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

        $view = $tpl['view'] . '.' . $useLocale;

        // Harde bestands-fallback: bestaat de view echt niet, val terug op de standaardtaal.
        if (! View::exists($view)) {
            $useLocale = $defaultLocale;
            $view = $tpl['view'] . '.' . $defaultLocale;
        }

        $subject = $tpl['subjects'][$useLocale]
            ?? $tpl['subjects'][$defaultLocale]
            ?? ($tpl['label'] ?? 'MilMap');

        return ['view' => $view, 'locale' => $useLocale, 'subject' => $subject];
    }
}
