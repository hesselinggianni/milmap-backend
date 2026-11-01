<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Een deellink toont een gebied uit een grid of straal gewoon als vlak; de
 * bewaarde waarde (saluteShape) gaat mee zodat het symbool op dezelfde plek
 * staat als in de app (bij een straal op de rand, niet op het waypoint).
 */
class MarkedAreaShapeShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_public_link_carries_the_shape_of_a_marked_area_without_the_private_rest(): void
    {
        $owner = User::create(['first_name' => 'Eigenaar', 'last_name' => 'Test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now(), 'trial_ends_at' => now()->addDays(3)]);
        $map = Map::create(['owner_id' => $owner->id, 'title' => 'Kaart', 'status' => 'active']);
        $shape = ['kind' => 'circle', 'radius' => 250, 'center' => [5.4697, 51.4416], 'waypointId' => 1700000000001];
        $polygon = ['type' => 'Polygon', 'coordinates' => [[[5.466, 51.4416], [5.4697, 51.4438], [5.4734, 51.4416], [5.466, 51.4416]]]];
        Report::create([
            'map_id' => $map->id, 'user_id' => $owner->id, 'category' => 'threat', 'type' => 'infantry',
            'latitude' => 51.4416, 'longitude' => 5.4697, 'description' => 'Privé',
            'metadata' => ['title' => 'Basis – straal 250 m', 'saluteGeometry' => $polygon, 'saluteShape' => $shape, 'interneNotitie' => 'niet delen'],
        ]);

        app('auth')->forgetGuards();
        $token = $this->withToken($owner->createToken('test', ['user'], now()->addHour())->plainTextToken)
            ->postJson("/api/v1/maps/{$map->id}/shares", ['content' => ['area_details' => false]])
            ->assertCreated()->json('token');

        app('auth')->forgetGuards();
        $report = $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/share/'.$token)
            ->assertOk()->json('reports.0');

        $this->assertEquals($shape, $report['metadata']['saluteShape']);
        $this->assertEquals($polygon, $report['metadata']['saluteGeometry']);
        $this->assertSame('Basis – straal 250 m', $report['metadata']['title']);
        // Zonder gebiedsdetails blijft de rest van de melding privé.
        $this->assertArrayNotHasKey('interneNotitie', $report['metadata']);
        $this->assertArrayNotHasKey('description', $report);
    }
}
