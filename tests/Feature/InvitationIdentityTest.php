<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackConnectDevice;
use App\Models\Invitation;
use App\Models\Mission;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InvitationIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This regression suite never runs migrations against a developer DB.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'geoblock.countries' => []]);
        DB::purge('sqlite');
        // Device bookkeeping is separate from invitation identity. Keep actual
        // auth, email verification, view-only and Connect policy middleware.
        $this->withoutMiddleware(TrackConnectDevice::class);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('first_name')->default('Test');
            $table->string('last_name')->default('User');
            $table->boolean('view_only')->default(false);
            $table->boolean('connect_device_account')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('missions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->unsignedBigInteger('connect_organization_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('mission_collaborators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mission_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('added_by');
            $table->string('role');
            $table->string('status');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['mission_id', 'user_id']);
        });
        (require database_path('migrations/2026_06_05_150000_create_invitations_table.php'))->up();

        DB::table('users')->insert([
            ['id' => 1, 'email' => 'owner@example.test', 'email_verified_at' => now()],
            ['id' => 2, 'email' => 'recipient@example.test', 'email_verified_at' => now()],
            ['id' => 3, 'email' => 'other@example.test', 'email_verified_at' => now()],
        ]);
        DB::table('missions')->insert(['id' => 'mission-test', 'owner_id' => 1, 'name' => 'Training']);
    }

    private function invite(array $attributes = []): Invitation
    {
        return Invitation::create(array_merge([
            'email' => 'recipient@example.test',
            'invited_user_id' => 2,
            'invitable_type' => Mission::class,
            'invitable_id' => 'mission-test',
            'role' => 'editor',
            'invited_by' => 1,
            'token' => Str::random(64),
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ], $attributes));
    }

    private function path(Invitation $invite, string $action): string
    {
        return '/api/v1/invitations/token/'.$invite->token.'/'.$action;
    }

    public function test_context_and_actions_require_authentication(): void
    {
        $invite = $this->invite();
        $this->getJson($this->path($invite, 'context'))->assertUnauthorized();
        $this->postJson($this->path($invite, 'accept'))->assertUnauthorized();
        $this->postJson($this->path($invite, 'decline'))->assertUnauthorized();
        $this->assertSame('pending', $invite->fresh()->status);
    }

    public function test_context_uses_current_server_account_and_is_not_cacheable(): void
    {
        $invite = $this->invite();
        $this->actingAs(User::findOrFail(2), 'sanctum');
        $response = $this->getJson($this->path($invite, 'context'))
            ->assertOk()
            ->assertExactJson(['matches_recipient' => true]);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    public function test_context_never_reveals_either_email_address(): void
    {
        $invite = $this->invite();
        foreach ([2 => true, 3 => false] as $userId => $matches) {
            $this->actingAs(User::findOrFail($userId), 'sanctum');
            $response = $this->getJson($this->path($invite, 'context'))
                ->assertOk()
                ->assertExactJson(['matches_recipient' => $matches]);
            $this->assertStringNotContainsString('@', $response->getContent());
        }
    }

    public function test_wrong_account_cannot_accept_or_decline_and_client_identity_is_ignored(): void
    {
        $invite = $this->invite();
        $this->actingAs(User::findOrFail(3), 'sanctum');
        $this->getJson($this->path($invite, 'context'))
            ->assertOk()->assertExactJson(['matches_recipient' => false]);

        foreach (['accept', 'decline'] as $action) {
            $response = $this->postJson($this->path($invite, $action), ['user_id' => 2, 'email' => $invite->email])
                ->assertForbidden();
            // Ook de foutmelding blijft anoniem.
            $this->assertStringNotContainsString('@', (string) $response->json('message'));
        }
        $this->assertSame('pending', $invite->fresh()->status);
        $this->assertNull($invite->fresh()->accepted_by);
        $this->assertDatabaseCount('mission_collaborators', 0);
    }

    public function test_recipient_can_accept_and_membership_is_only_created_for_that_account(): void
    {
        $invite = $this->invite();
        $this->actingAs(User::findOrFail(2), 'sanctum');
        $this->postJson($this->path($invite, 'accept'))
            ->assertOk()->assertJsonPath('resource_type', 'mission')->assertJsonPath('resource_id', 'mission-test');
        $this->assertDatabaseHas('mission_collaborators', ['mission_id' => 'mission-test', 'user_id' => 2, 'status' => 'accepted', 'role' => 'editor']);
        $this->assertSame(2, $invite->fresh()->accepted_by);
        $this->assertSame('accepted', $invite->fresh()->status);
    }

    public function test_bound_invitation_follows_account_after_email_change(): void
    {
        $invite = $this->invite();
        DB::table('users')->where('id', 2)->update(['email' => 'changed@example.test']);
        $this->actingAs(User::findOrFail(2), 'sanctum');
        $this->getJson($this->path($invite, 'context'))
            ->assertOk()->assertExactJson(['matches_recipient' => true]);
        $this->postJson($this->path($invite, 'accept'))->assertOk();
        $this->assertSame(2, $invite->fresh()->accepted_by);
    }

    public function test_reassigned_email_does_not_transfer_invitation_to_another_account(): void
    {
        $invite = $this->invite();
        DB::table('users')->where('id', 2)->update(['email' => 'changed@example.test']);
        DB::table('users')->where('id', 3)->update(['email' => 'recipient@example.test']);
        $other = User::findOrFail(3);
        $this->actingAs($other, 'sanctum');

        $this->getJson($this->path($invite, 'context'))->assertOk()->assertJsonPath('matches_recipient', false);
        $this->postJson($this->path($invite, 'accept'))->assertForbidden();
        $this->postJson($this->path($invite, 'decline'))->assertForbidden();
        $this->getJson('/api/v1/invitations/incoming')->assertOk()->assertJsonPath('invitations', []);
        $this->assertSame(0, app(InvitationService::class)->convertForNewUser($other));
        $this->assertSame(2, $invite->fresh()->invited_user_id);
        $this->assertSame('pending', $invite->fresh()->status);
        $this->assertDatabaseCount('mission_collaborators', 0);
    }

    public function test_email_only_invitation_normalizes_case_and_whitespace(): void
    {
        $invite = $this->invite(['invited_user_id' => null, 'email' => '  ReCiPiEnT@Example.test  ']);
        $this->actingAs(User::findOrFail(2), 'sanctum');
        $this->getJson($this->path($invite, 'context'))->assertOk()->assertJsonPath('matches_recipient', true);
        $this->getJson('/api/v1/invitations/incoming')->assertOk()->assertJsonCount(1, 'invitations');
        $this->postJson($this->path($invite, 'accept'))->assertOk();
        $this->assertSame(2, $invite->fresh()->accepted_by);
    }

    public function test_recipient_can_decline_without_joining_mission(): void
    {
        $invite = $this->invite();
        $this->actingAs(User::findOrFail(2), 'sanctum');
        $this->postJson($this->path($invite, 'decline'))->assertOk();
        $this->assertSame('declined', $invite->fresh()->status);
        $this->assertDatabaseCount('mission_collaborators', 0);
    }

    public function test_service_itself_rejects_different_recipient_before_any_mutation(): void
    {
        $invite = $this->invite();
        try {
            app(InvitationService::class)->accept($invite, User::findOrFail(3));
            $this->fail('Expected recipient authorization failure');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame('pending', $invite->fresh()->status);
        $this->assertDatabaseCount('mission_collaborators', 0);
    }

    public function test_missing_invitation_is_not_found_and_public_preview_has_no_session_identity(): void
    {
        $invite = $this->invite();
        $this->getJson('/api/v1/invitations/token/'.$invite->token)->assertOk()
            ->assertJsonMissingPath('authenticated_user')->assertJsonMissingPath('matches_recipient');
        $this->actingAs(User::findOrFail(2), 'sanctum');
        $this->getJson('/api/v1/invitations/token/missing/context')->assertNotFound();
    }

    public function test_public_preview_only_exposes_recipient_address_for_registration(): void
    {
        // Bestaand account: geen adres in de preview, ook niet voor een ander
        // ingelogd account dat de link opent.
        $existing = $this->invite();
        $this->actingAs(User::findOrFail(3), 'sanctum');
        $response = $this->getJson('/api/v1/invitations/token/'.$existing->token)->assertOk()
            ->assertJsonPath('invitation.user_exists', true)
            ->assertJsonMissingPath('invitation.email');
        $this->assertStringNotContainsString('@', $response->getContent());

        // Gebonden uitnodiging na adreswijziging: account bestaat nog steeds.
        DB::table('users')->where('id', 2)->update(['email' => 'changed@example.test']);
        $this->getJson('/api/v1/invitations/token/'.$existing->token)->assertOk()
            ->assertJsonPath('invitation.user_exists', true)
            ->assertJsonMissingPath('invitation.email');

        // Nog geen account: het adres vult het registratieformulier vooraf in.
        $newcomer = $this->invite(['invited_user_id' => null, 'email' => 'new@example.test']);
        $this->getJson('/api/v1/invitations/token/'.$newcomer->token)->assertOk()
            ->assertJsonPath('invitation.user_exists', false)
            ->assertJsonPath('invitation.email', 'new@example.test');
    }
}
