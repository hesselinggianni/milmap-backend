<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackConnectDevice;
use App\Models\Invitation;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvitationTimelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'geoblock.countries' => []]);
        DB::purge('sqlite');
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
        Schema::create('public_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('handle');
            $table->timestamps();
        });
        // De tijdlijn bevraagt ook teams, gesprekken en chatuitnodigingen; voor deze
        // test blijven die tabellen leeg, ze moeten alleen bestaan.
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
        });
        Schema::create('team_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type')->default('direct');
            $table->timestamps();
        });
        Schema::create('conversation_user', function (Blueprint $table) {
            $table->uuid('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });
        Schema::create('chat_invites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inviter_id');
            $table->string('email')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('accepted_user_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('chat_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('requester_id');
            $table->unsignedBigInteger('recipient_id');
            $table->string('status')->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_06_05_150000_create_invitations_table.php'))->up();
        (require database_path('migrations/2026_10_31_190000_add_email_hidden_to_invitations.php'))->up();

        DB::table('users')->insert([
            ['id' => 1, 'email' => 'owner@example.test', 'email_verified_at' => now()],
            ['id' => 2, 'email' => 'secret@example.test', 'email_verified_at' => now()],
        ]);
        DB::table('missions')->insert(['id' => 'mission-test', 'owner_id' => 1, 'name' => 'Training']);
        DB::table('public_profiles')->insert(['user_id' => 2, 'handle' => 'recruit']);
    }

    private function invite(array $attributes = []): Invitation
    {
        return Invitation::create(array_merge([
            'email' => 'secret@example.test',
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

    // Regressie: de "verstuurd"-tak van de tijdlijn gebruikte $user zonder het in
    // de closure te importeren. Dat gaf "Undefined variable $user" → een 500 voor
    // iedereen die ooit een uitnodiging verstuurde.
    public function test_timeline_works_for_a_user_who_sent_an_invitation(): void
    {
        $this->invite();
        $this->actingAs(User::findOrFail(1), 'sanctum');

        $events = $this->getJson('/api/v1/activity/timeline')->assertOk()->json('events');

        $sent = collect($events)->firstWhere('type', 'invite_sent');
        $this->assertNotNull($sent);
        $this->assertStringContainsString('secret@example.test', $sent['subtitle']);
    }

    public function test_timeline_hides_the_address_of_someone_invited_by_username(): void
    {
        $this->invite(['email_hidden' => true]);
        $this->actingAs(User::findOrFail(1), 'sanctum');

        $response = $this->getJson('/api/v1/activity/timeline')->assertOk();

        $this->assertStringNotContainsString('secret@example.test', $response->getContent());
        $this->assertStringContainsString('@recruit', $response->getContent());
    }
}
