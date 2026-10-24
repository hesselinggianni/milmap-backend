<?php
namespace Tests\Feature;

use App\Models\{ConnectMember, ConnectOrganization, Mission, User};
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Uitnodigen en accepteren van een Connect-missie, intern en extern. */
class ConnectInvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Mail::fake(); }

    private function user(string $tag): User
    {
        return User::create(['first_name' => $tag, 'last_name' => 'Test', 'email' => Str::uuid().'-'.$tag.'@example.test', 'password' => bcrypt('x-password-1'), 'email_verified_at' => now(), 'trial_ends_at' => now()->addDay()]);
    }

    private function setup_org(array $policy = []): array
    {
        $owner = $this->user('owner'); $inside = $this->user('inside'); $outside = $this->user('outside');
        $org = ConnectOrganization::create(['name' => 'School', 'seat_limit' => 5, 'restrict_to_allowed_devices' => false, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'management_policy' => $policy]);
        foreach ([[$owner, 'owner'], [$inside, 'member']] as [$u, $role]) {
            ConnectMember::create(['organization_id' => $org->id, 'user_id' => $u->id, 'email' => $u->email, 'first_name' => 'T', 'last_name' => 'U', 'role' => $role, 'status' => 'active', 'tags' => []]);
        }
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Connect-missie']);
        $mission->forceFill(['connect_organization_id' => $org->id])->save();
        return [$owner, $inside, $outside, $org, $mission];
    }

    private array $devices = [];

    private function as(User $u): self { app('auth')->forgetGuards(); return $this->withHeaders(['X-Milmap-Installation' => $this->devices[$u->id] ??= (string) Str::uuid()])->withToken($u->createToken('t', ['user'], now()->addHour())->plainTextToken); }

    public function test_internal_member_sees_and_accepts_the_invitation(): void
    {
        [$owner, $inside, , , $mission] = $this->setup_org();
        $r = app(InvitationService::class)->createInvite($mission, $inside->email, 'viewer', $owner);
        $this->assertTrue($r['ok']);

        $inc = $this->as($inside)->getJson('/api/v1/invitations/incoming'); $list = $inc->assertOk()->json('invitations');
        $this->assertCount(1, $list);

        $res = $this->as($inside)->postJson('/api/v1/invitations/token/'.$r['invitation']->token.'/accept');
        $res->assertOk()->assertJsonPath('resource_type', 'mission')->assertJsonPath('resource_id', $mission->id);
    }

    public function test_external_invite_is_blocked_by_default_and_works_when_policy_allows(): void
    {
        [$owner, , $outside, $org, $mission] = $this->setup_org();
        try {
            app(InvitationService::class)->createInvite($mission, $outside->email, 'viewer', $owner);
            $this->fail('extern delen hoort geweigerd te worden');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_external_invite_when_policy_allows_can_be_accepted(): void
    {
        [$owner, , $outside, , $mission] = $this->setup_org(['share_external' => true]);
        $r = app(InvitationService::class)->createInvite($mission, $outside->email, 'viewer', $owner);
        $this->as($outside)->getJson('/api/v1/invitations/incoming')->assertOk()->assertJsonCount(1, 'invitations');
        $this->as($outside)->postJson('/api/v1/invitations/token/'.$r['invitation']->token.'/accept')->assertOk();
    }

    public function test_sharing_switched_off_after_invite_refuses_accept_with_a_clear_message(): void
    {
        [, $inside, , $org, $mission] = $this->setup_org();
        // Een gewoon lid nodigt uit (een eigenaar of beheerder is vrij van het beleid, zie de test hieronder).
        $colleague = $this->user('colleague');
        ConnectMember::create(['organization_id' => $org->id, 'user_id' => $colleague->id, 'email' => $colleague->email, 'first_name' => 'C', 'last_name' => 'U', 'role' => 'member', 'status' => 'active', 'tags' => []]);
        $r = app(InvitationService::class)->createInvite($mission, $inside->email, 'viewer', $colleague);
        $org->forceFill(['management_policy' => ['share_internal' => false]])->save();
        $this->as($inside)->postJson('/api/v1/invitations/token/'.$r['invitation']->token.'/accept')
            ->assertForbidden()->assertJsonFragment(['message' => 'Delen is door de organisatie uitgeschakeld.']);
    }

    public function test_owner_is_not_bound_by_the_internal_sharing_policy_of_their_own_organisation(): void
    {
        [$owner, $inside, , $org, $mission] = $this->setup_org(['share_internal' => false]);
        $r = app(InvitationService::class)->createInvite($mission, $inside->email, 'viewer', $owner);
        $this->as($inside)->postJson('/api/v1/invitations/token/'.$r['invitation']->token.'/accept')->assertOk();
    }
}
