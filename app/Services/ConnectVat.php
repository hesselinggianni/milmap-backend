<?php
namespace App\Services;

use Illuminate\Support\Facades\{Cache, Http, Log};

/**
 * Btw-behandeling van een Connect-bestelling (verkoper in Nederland, digitale dienst):
 *  - NL-klant: 21% btw;
 *  - EU-bedrijf buiten NL met geldig btw-nummer: btw verlegd (0%);
 *  - EU-klant buiten NL zonder btw-nummer: 21% Nederlandse btw (consumentregel, onder de OSS-drempel);
 *  - klant buiten de EU: geen Nederlandse btw (0%).
 * De frontend (MilMap-Frontend/src/services/connectVat.js) volgt exact dezelfde regels.
 */
class ConnectVat
{
    /** EU-lidstaten (ISO-landcodes) en hun VIES-prefix waar die afwijkt (Griekenland = EL). */
    public const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT',
        'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];
    private const VIES_PREFIX = ['GR' => 'EL'];

    public const NL = 'nl';
    public const REVERSE_CHARGE = 'reverse_charge';
    public const EU_CONSUMER = 'eu_consumer';
    public const OUTSIDE_EU = 'outside_eu';

    public static function isEu(string $country): bool { return in_array(strtoupper($country), self::EU, true); }

    /** Btw-nummer zonder spaties/punten, in hoofdletters; '' als leeg. */
    public static function normalize(?string $vatId): string
    {
        return strtoupper(preg_replace('/[\s.\-]/', '', (string) $vatId));
    }

    /** Verwacht voorvoegsel van een btw-nummer voor dit land (GR → EL). */
    public static function prefix(string $country): string
    {
        $country = strtoupper($country);
        return self::VIES_PREFIX[$country] ?? $country;
    }

    /** Vormcontrole: landprefix + 2–12 letters/cijfers, passend bij het factuurland. */
    public static function wellFormed(string $country, string $vatId): bool
    {
        return self::isEu($country) && (bool) preg_match('/^'.self::prefix($country).'[0-9A-Z]{2,12}$/', $vatId);
    }

    /**
     * @return array{treatment:string,percent:int,label:string,tax_exempt:string}
     */
    public static function treatment(string $country, bool $validEuVatId, int $domesticPercent): array
    {
        $country = strtoupper($country);
        if ($country === 'NL') return ['treatment' => self::NL, 'percent' => $domesticPercent, 'label' => "Btw {$domesticPercent}%", 'tax_exempt' => 'none'];
        if (self::isEu($country)) {
            return $validEuVatId
                ? ['treatment' => self::REVERSE_CHARGE, 'percent' => 0, 'label' => 'Btw verlegd (0%)', 'tax_exempt' => 'reverse']
                : ['treatment' => self::EU_CONSUMER, 'percent' => $domesticPercent, 'label' => "Btw {$domesticPercent}%", 'tax_exempt' => 'none'];
        }
        return ['treatment' => self::OUTSIDE_EU, 'percent' => 0, 'label' => 'Geen Nederlandse btw (buiten de EU)', 'tax_exempt' => 'exempt'];
    }

    /**
     * Controle bij de EU (VIES). valid=null betekent: VIES nu niet bereikbaar.
     * @return array{valid:?bool,name:?string}
     */
    public function check(string $country, string $vatId): array
    {
        if (!self::wellFormed($country, $vatId)) return ['valid' => false, 'name' => null];
        // Alleen een echt VIES-antwoord bewaren; een storing niet, dan wordt het later opnieuw geprobeerd.
        if (is_array($cached = Cache::get('vies:'.$vatId))) return $cached;
        try {
            $res = Http::timeout(6)->acceptJson()->post('https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number', [
                'countryCode' => substr($vatId, 0, 2), 'vatNumber' => substr($vatId, 2),
            ]);
            if (!$res->ok() || !is_bool($res->json('valid'))) throw new \RuntimeException('VIES antwoord '.$res->status());
        } catch (\Throwable $e) {
            Log::warning('VIES-controle niet beschikbaar', ['vat' => substr($vatId, 0, 4).'…', 'error' => $e->getMessage()]);
            return ['valid' => null, 'name' => null];
        }
        $name = trim((string) $res->json('name'));
        $result = ['valid' => $res->json('valid'), 'name' => $name !== '' && $name !== '---' ? $name : null];
        Cache::put('vies:'.$vatId, $result, now()->addHours(12));
        return $result;
    }
}
