<?php

namespace Tests\Unit;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\SocialPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Geïsoleerde test voor SocialPublisher. GEEN RefreshDatabase (die zou de
 * dev-DB wissen) — alleen veilige SELECTs + een teruggedraaide transactie voor
 * de Pinterest-account-rij. Draai alléén dit bestand:
 *   php artisan test tests/Unit/SocialPublisherTest.php
 */
class SocialPublisherTest extends TestCase
{
    private function makePost(): SocialPost
    {
        // Niet opgeslagen model — imageUrl() werkt op image_path zonder DB.
        return new SocialPost([
            'title'       => 'MilMap op desktop',
            'description' => 'Jouw commandopost in de browser.',
            'link'        => 'https://www.milmap.nl',
            'image_path'  => 'social/voorbeeld.png',
        ]);
    }

    public function test_instagram_levert_manual_assist_payload(): void
    {
        $res = (new SocialPublisher())->publish($this->makePost(), 'instagram');

        $this->assertSame('manual', $res['state']);
        $this->assertStringContainsString('MilMap op desktop', $res['caption']);
        $this->assertStringContainsString('milmap.nl', $res['caption']);
        $this->assertStringContainsString('/storage/social/voorbeeld.png', $res['image_url']);
        $this->assertSame('https://www.instagram.com/', $res['composer_url']);
    }

    public function test_dribbble_levert_manual_assist_met_upload_link(): void
    {
        $res = (new SocialPublisher())->publish($this->makePost(), 'dribbble');

        $this->assertSame('manual', $res['state']);
        $this->assertSame('https://dribbble.com/uploads/new', $res['composer_url']);
    }

    public function test_pinterest_zonder_koppeling_valt_terug_op_manual(): void
    {
        // Aanname: geen pinterest-account in de dev-DB (veilige SELECT).
        if (SocialAccount::where('platform', 'pinterest')->exists()) {
            $this->markTestSkipped('Er bestaat al een pinterest-account in deze DB.');
        }
        $res = (new SocialPublisher())->publish($this->makePost(), 'pinterest');
        $this->assertSame('manual', $res['state']);
    }

    public function test_pinterest_met_koppeling_roept_v5_api_aan(): void
    {
        Http::fake([
            'api.pinterest.com/v5/pins' => Http::response(['id' => '12345'], 201),
        ]);

        DB::beginTransaction();
        try {
            SocialAccount::updateOrCreate(
                ['platform' => 'pinterest'],
                ['access_token' => 'pina_testtoken', 'board_id' => 'board-99'],
            );

            $res = (new SocialPublisher())->publish($this->makePost(), 'pinterest');

            $this->assertSame('published', $res['state']);
            $this->assertSame('https://www.pinterest.com/pin/12345/', $res['url']);

            Http::assertSent(function ($request) {
                $body = $request->data();
                return $request->url() === 'https://api.pinterest.com/v5/pins'
                    && $body['board_id'] === 'board-99'
                    && $body['media_source']['source_type'] === 'image_url'
                    && str_contains($body['media_source']['url'], '/storage/social/voorbeeld.png')
                    && $body['link'] === 'https://www.milmap.nl';
            });
        } finally {
            DB::rollBack(); // dev-DB blijft ongewijzigd
        }
    }
}
