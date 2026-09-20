<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActivitySharingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->withoutMiddleware([
            \App\Http\Middleware\TrackLastSeen::class,
            \App\Http\Middleware\EnsureEmailVerified::class,
            \App\Http\Middleware\EnsureNotViewOnly::class,
        ]);
        Schema::create('activities', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->string('title'); $table->text('points');
        });
        Schema::create('activity_photos', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('activity_id'); $table->string('url');
        });
        DB::table('activities')->insert(['id' => 1, 'user_id' => 10, 'title' => 'Morning walk', 'points' => '[{"lat":52,"lon":5},{"lat":52.01,"lon":5.01}]']);
    }

    private function loginAs(int $id): void
    {
        $user = new User(); $user->id = $id;
        $this->actingAs($user, 'sanctum');
    }

    public function test_owner_can_share_and_recipient_can_only_read_with_valid_link(): void
    {
        $this->loginAs(10);
        $path = $this->postJson('/api/v1/activities/1/share')->assertOk()->json('path');
        $this->loginAs(20);
        $this->getJson($path)->assertOk()->assertJsonPath('activity.title', 'Morning walk')->assertJsonMissingPath('activity.user_id');
        $this->getJson('/api/v1/activities/1')->assertNotFound();
        $this->postJson('/api/v1/activities/1/share')->assertNotFound();
        $this->putJson('/api/v1/activities/1', ['title' => 'Changed'])->assertNotFound();
        $this->deleteJson('/api/v1/activities/1')->assertNotFound();
    }

    public function test_missing_tampered_and_expired_signatures_are_rejected(): void
    {
        $this->loginAs(10);
        $path = $this->postJson('/api/v1/activities/1/share')->assertOk()->json('path');
        $this->loginAs(20);
        $this->getJson('/api/v1/activities/1/shared')->assertForbidden();
        $this->getJson(str_replace('/1/shared', '/2/shared', $path))->assertForbidden();
        $this->travel(8)->days();
        $this->getJson($path)->assertForbidden();
    }

    public function test_guests_can_only_read_explicitly_signed_shared_routes(): void
    {
        $this->getJson('/api/v1/activities/1/shared')->assertForbidden();
        $path = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'activities.shared', now()->addDay(), ['id' => 1], false
        );
        $this->getJson($path)->assertOk()->assertJsonMissingPath('activity.user_id');
        $this->getJson('/api/v1/activities/1')->assertUnauthorized();
    }
}
