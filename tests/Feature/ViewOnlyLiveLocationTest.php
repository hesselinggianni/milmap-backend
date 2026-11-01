<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureNotViewOnly;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Een view-only account (via e-mail uitgenodigd, nog geen abonnement) mag zijn
 * eigen live positie delen, maar geen kaartinhoud schrijven. Kaart-id's zijn
 * UUID's: de allow-list mocht daar niet op `\d+` matchen.
 */
class ViewOnlyLiveLocationTest extends TestCase
{
    private const MAP_ID = '9b2f0a1e-4c3d-4e5f-8a6b-7c8d9e0f1a2b';

    private function passes(string $method, string $uri): bool
    {
        $user = new class {
            public int $id = 7;
            public bool $is_admin = false;

            public function isViewOnly(): bool
            {
                return true;
            }
        };

        $request = Request::create($uri, $method);
        $request->setUserResolver(fn () => $user);

        $response = (new EnsureNotViewOnly())->handle(
            $request,
            fn () => new Response('ok', 200)
        );

        return $response->getStatusCode() === 200;
    }

    public function test_eigen_positie_delen_mag_met_uuid_kaart_id(): void
    {
        $this->assertTrue($this->passes('POST', '/api/v1/maps/' . self::MAP_ID . '/user-locations/update'));
        $this->assertTrue($this->passes('DELETE', '/api/v1/maps/' . self::MAP_ID . '/user-locations/stop-sharing'));
    }

    public function test_numeriek_kaart_id_blijft_werken(): void
    {
        $this->assertTrue($this->passes('POST', '/api/v1/maps/42/user-locations/update'));
    }

    public function test_markers_schrijven_blijft_geblokkeerd(): void
    {
        $this->assertFalse($this->passes('POST', '/api/v1/maps/' . self::MAP_ID . '/locations'));
        $this->assertFalse($this->passes('DELETE', '/api/v1/maps/' . self::MAP_ID . '/locations'));
    }

    public function test_alleen_het_user_locations_pad_telt(): void
    {
        $this->assertFalse($this->passes('POST', '/api/v1/maps/' . self::MAP_ID . '/user-locations-evil'));
        $this->assertFalse($this->passes('POST', '/api/v1/maps/' . self::MAP_ID . '/x/user-locations'));
    }

    public function test_lezen_mag_altijd(): void
    {
        $this->assertTrue($this->passes('GET', '/api/v1/maps/' . self::MAP_ID . '/user-locations'));
    }
}
