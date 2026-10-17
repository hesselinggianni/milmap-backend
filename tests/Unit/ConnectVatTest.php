<?php
namespace Tests\Unit;

use App\Services\ConnectVat;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectVatTest extends TestCase
{
    public function test_treatment_per_country_and_vat_id(): void
    {
        $this->assertSame(['treatment' => 'nl', 'percent' => 21, 'label' => 'Btw 21%', 'tax_exempt' => 'none'], ConnectVat::treatment('NL', true, 21));
        $this->assertSame('reverse_charge', ConnectVat::treatment('DE', true, 21)['treatment']);
        $this->assertSame(0, ConnectVat::treatment('DE', true, 21)['percent']);
        $this->assertSame('reverse', ConnectVat::treatment('BE', true, 21)['tax_exempt']);
        $this->assertSame(['eu_consumer', 21], [ConnectVat::treatment('BE', false, 21)['treatment'], ConnectVat::treatment('BE', false, 21)['percent']]);
        $this->assertSame(['outside_eu', 0, 'exempt'], array_values(array_intersect_key(ConnectVat::treatment('GB', true, 21), array_flip(['treatment', 'percent', 'tax_exempt']))));
        $this->assertSame('outside_eu', ConnectVat::treatment('us', false, 21)['treatment']);
    }

    public function test_vat_id_format_must_match_the_billing_country(): void
    {
        $this->assertSame('DE123456789', ConnectVat::normalize(' de 123.456-789 '));
        $this->assertTrue(ConnectVat::wellFormed('DE', 'DE123456789'));
        $this->assertFalse(ConnectVat::wellFormed('BE', 'DE123456789'));
        $this->assertTrue(ConnectVat::wellFormed('GR', 'EL123456789'));
        $this->assertFalse(ConnectVat::wellFormed('GB', 'GB123456789'));
        $this->assertFalse(ConnectVat::wellFormed('NL', 'NL1'));
    }

    public function test_vies_check_handles_valid_invalid_and_unreachable(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['valid' => true, 'name' => 'ACME GMBH'])
                ->push(['valid' => false, 'name' => '---'])
                ->push('', 503)
                ->push(['valid' => true, 'name' => 'SARL'])
        ]);
        $vat = app(ConnectVat::class);
        $this->assertSame(['valid' => true, 'name' => 'ACME GMBH'], $vat->check('DE', 'DE123456789'));
        $this->assertSame(['valid' => true, 'name' => 'ACME GMBH'], $vat->check('DE', 'DE123456789'), 'resultaat gecachet');
        $this->assertSame(['valid' => false, 'name' => null], $vat->check('BE', 'BE0123456789'));
        $this->assertSame(['valid' => null, 'name' => null], $vat->check('FR', 'FR12345678901'));
        $this->assertSame(['valid' => true, 'name' => 'SARL'], $vat->check('FR', 'FR12345678901'), 'een storing wordt niet bewaard');
        $this->assertSame(['valid' => false, 'name' => null], $vat->check('DE', 'XX1'), 'onjuiste vorm: geen VIES-aanroep');
        Http::assertSentCount(4);
    }
}
