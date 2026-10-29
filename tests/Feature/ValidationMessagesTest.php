<?php
namespace Tests\Feature;

use Tests\TestCase;

/**
 * Een 422 geeft een leesbare melding, nooit de ruwe sleutel ("validation.in").
 * De taal volgt de bovenste voorkeur in Accept-Language: nl, anders Engels.
 */
class ValidationMessagesTest extends TestCase
{
    /** Publiek endpoint dat zonder inhoud op `type` en `message` valideert. */
    private function melding(?string $acceptLanguage): array
    {
        $headers = $acceptLanguage === null ? [] : ['Accept-Language' => $acceptLanguage];
        $res = $this->postJson('/api/v1/client-errors', [], $headers)->assertStatus(422);

        return $res->json('errors.type');
    }

    public function test_engels_is_de_standaard_en_geen_ruwe_sleutel(): void
    {
        $this->assertSame(['The type field is required.'], $this->melding(null));
        $this->assertSame(['The type field is required.'], $this->melding('en-US,en;q=0.9'));
    }

    public function test_nederlands_als_dat_bovenaan_de_voorkeur_staat(): void
    {
        $this->assertSame(['Het veld type is verplicht.'], $this->melding('nl-NL,nl;q=0.9,en;q=0.8'));
    }

    public function test_nl_als_tweede_taal_geeft_geen_nederlands(): void
    {
        $this->assertSame(['The type field is required.'], $this->melding('de-DE,de;q=0.9,nl;q=0.5'));
    }

    public function test_de_taal_hangt_niet_af_van_app_locale_op_de_server(): void
    {
        // Een server met APP_LOCALE=nl (of iets onbekends) geeft Engelse clients toch Engels.
        app()->setLocale('nl');
        $this->assertSame(['The type field is required.'], $this->melding('fr-FR'));
        app()->setLocale('xx');
        $this->assertSame(['The type field is required.'], $this->melding(null));
    }

    public function test_beide_talen_hebben_dezelfde_sleutels(): void
    {
        $vlak = function (array $a, string $pad = '') use (&$vlak): array {
            $uit = [];
            foreach ($a as $k => $v) {
                is_array($v) ? $uit += $vlak($v, "$pad$k.") : $uit["$pad$k"] = true;
            }
            return $uit;
        };
        $en = $vlak(require lang_path('en/validation.php'));
        $nl = $vlak(require lang_path('nl/validation.php'));

        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($nl))), 'ontbreekt in nl');
        $this->assertSame([], array_values(array_filter(array_diff(array_keys($nl), array_keys($en)), fn ($k) => ! str_starts_with($k, 'attributes.'))), 'extra in nl');
    }
}
