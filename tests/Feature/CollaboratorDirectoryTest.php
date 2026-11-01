<?php
namespace Tests\Feature;

use App\Models\{ConnectMember, ConnectOrganization, Mission, MissionCollaborator, PublicProfile, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Gebruiker-tab van de missie-deeldialoog: wie vindbaar is, en wie direct toegevoegd mag worden. */
class CollaboratorDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); Mail::fake(); }

    private function user(string $first, string $last = 'Test', ?string $email = null): User
    {
        return User::create(['first_name' => $first, 'last_name' => $last, 'email' => $email ?? strtolower($first).'.'.Str::random(6).'@example.test', 'password' => bcrypt('x-password-1'), 'email_verified_at' => now(), 'trial_ends_at' => now()->addDay()]);
    }

    private function org(array $policy = []): ConnectOrganization
    {
        return ConnectOrganization::create(['name' => 'School', 'seat_limit' => 50, 'restrict_to_allowed_devices' => false, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'management_policy' => $policy]);
    }

    private function join(ConnectOrganization $org, User $u, string $role = 'member', string $status = 'active'): void
    {
        ConnectMember::create(['organization_id' => $org->id, 'user_id' => $u->id, 'email' => $u->email, 'first_name' => $u->first_name, 'last_name' => $u->last_name, 'role' => $role, 'status' => $status, 'tags' => []]);
    }

    private function profile(User $u, string $handle, bool $enabled = true): void
    {
        PublicProfile::create(['user_id' => $u->id, 'handle' => $handle, 'display_name' => 'Openbaar '.$handle, 'enabled' => $enabled]);
    }

    private array $devices = [];

    private function as(User $u): self { app('auth')->forgetGuards(); return $this->withHeaders(['X-Milmap-Installation' => $this->devices[$u->id] ??= (string) Str::uuid()])->withToken($u->createToken('t', ['user'], now()->addHour())->plainTextToken); }

    private function search(User $as, string $q): array
    {
        return $this->as($as)->getJson('/api/v1/users/directory?q='.urlencode($q))->assertOk()->json('users');
    }

    public function test_connect_colleagues_are_found_by_name_email_and_username_with_email_visible(): void
    {
        $org = $this->org();
        $me = $this->user('Ik'); $anna = $this->user('Anna', 'Jansen', 'anna.jansen@school.test'); $bram = $this->user('Bram');
        foreach ([$me, $anna, $bram] as $u) $this->join($org, $u);
        $this->profile($anna, 'annaj');

        foreach (['anna', 'jansen', 'anna jansen', 'school.test', 'ANNA.JANSEN@school.test', 'annaj', '@annaj'] as $q) {
            $found = $this->search($me, $q);
            $this->assertCount(1, $found, "zoekterm {$q}");
            $this->assertSame($anna->id, $found[0]['id']);
            $this->assertSame('anna.jansen@school.test', $found[0]['email']);
            $this->assertSame('organization', $found[0]['source']);
            $this->assertTrue($found[0]['direct']);
            $this->assertSame('annaj', $found[0]['handle']);
        }
    }

    public function test_search_needs_two_characters_and_ignores_sql_wildcards(): void
    {
        $org = $this->org();
        $me = $this->user('Ik'); $anna = $this->user('Anna');
        $this->join($org, $me); $this->join($org, $anna);

        $this->assertSame([], $this->search($me, 'a'));
        $this->assertSame([], $this->search($me, '%%'));
        $this->assertSame([], $this->search($me, '__'));
    }

    public function test_strangers_other_organisations_and_inactive_members_are_not_found(): void
    {
        $org = $this->org(); $other = $this->org();
        $me = $this->user('Ik'); $stranger = $this->user('Zara', 'Vreemd', 'zara@elders.test');
        $otherOrgMember = $this->user('Zoe', 'Elders'); $pending = $this->user('Zeno', 'Wachtend');
        $this->join($org, $me); $this->join($other, $otherOrgMember); $this->join($org, $pending, 'member', 'pending');

        foreach (['zara', 'elders.test', 'zoe', 'zeno'] as $q) {
            $this->assertSame([], $this->search($me, $q), "zoekterm {$q}");
        }
    }

    public function test_a_user_outside_connect_only_finds_previous_collaborators(): void
    {
        $me = $this->user('Ik'); $partner = $this->user('Pieter', 'Partner'); $stranger = $this->user('Pietra', 'Vreemd');
        $mission = Mission::create(['owner_id' => $me->id, 'name' => 'M']);
        MissionCollaborator::create(['mission_id' => $mission->id, 'user_id' => $partner->id, 'added_by' => $me->id, 'role' => 'editor', 'status' => 'accepted', 'invited_at' => now(), 'accepted_at' => now()]);

        $found = $this->search($me, 'piet');
        $this->assertCount(1, $found);
        $this->assertSame($partner->id, $found[0]['id']);
        $this->assertSame('contact', $found[0]['source']);
    }

    public function test_device_accounts_are_never_listed(): void
    {
        $org = $this->org();
        $me = $this->user('Ik'); $ipad = $this->user('Klas', 'Ipad');
        $ipad->forceFill(['connect_device_account' => true])->save();
        $this->join($org, $me); $this->join($org, $ipad);

        $this->assertSame([], $this->search($me, 'klas'));
    }

    public function test_internal_sharing_switched_off_hides_colleagues_for_plain_members_but_not_for_managers(): void
    {
        $org = $this->org(['share_internal' => false]);
        $member = $this->user('Mia'); $admin = $this->user('Adam'); $anna = $this->user('Anna');
        $this->join($org, $member); $this->join($org, $admin, 'owner'); $this->join($org, $anna);

        $this->assertSame([], $this->search($member, 'anna'));
        $this->assertCount(1, $this->search($admin, 'anna'));
    }

    public function test_exact_username_of_a_stranger_gives_no_email_and_no_real_name_and_is_not_direct(): void
    {
        $me = $this->user('Ik'); $stranger = $this->user('Geheim', 'Echtenaam', 'geheim@elders.test');
        $this->profile($stranger, 'veldwerker');
        $off = $this->user('Uit'); $this->profile($off, 'verborgen', false);

        $found = $this->search($me, 'Veldwerker');
        $this->assertCount(1, $found);
        $this->assertSame($stranger->id, $found[0]['id']);
        $this->assertSame('username', $found[0]['source']);
        $this->assertFalse($found[0]['direct']);
        $this->assertNull($found[0]['email']);
        $this->assertSame('Openbaar veldwerker', $found[0]['name']);
        $this->assertStringNotContainsString('Echtenaam', json_encode($found));

        $this->assertSame([], $this->search($me, 'veldwerk'), 'een deel van een gebruikersnaam is geen treffer');
        $this->assertSame([], $this->search($me, 'verborgen'), 'een uitgeschakeld profiel is niet vindbaar');
    }

    public function test_results_are_capped(): void
    {
        $org = $this->org();
        $me = $this->user('Ik'); $this->join($org, $me);
        for ($i = 0; $i < 30; $i++) $this->join($org, $this->user('Leerling'.$i));

        $this->assertCount(20, $this->search($me, 'leerling'));
    }

    public function test_search_is_rate_limited_per_user(): void
    {
        $me = $this->user('Ik');
        for ($i = 0; $i < 60; $i++) $this->as($me)->getJson('/api/v1/users/directory?q=ab')->assertOk();
        $this->as($me)->getJson('/api/v1/users/directory?q=ab')->assertStatus(429);
    }

    public function test_direct_add_is_limited_to_contacts_and_organisation_members(): void
    {
        $org = $this->org();
        $me = $this->user('Ik'); $colleague = $this->user('Collega'); $stranger = $this->user('Vreemde');
        $this->join($org, $me, 'owner'); $this->join($org, $colleague);
        $mission = Mission::create(['owner_id' => $me->id, 'name' => 'M']);

        $this->as($me)->postJson("/api/v1/missions/{$mission->id}/collaborators", ['user_id' => $colleague->id, 'role' => 'viewer'])->assertCreated();
        $this->assertSame('accepted', MissionCollaborator::where('mission_id', $mission->id)->where('user_id', $colleague->id)->value('status'));

        // Binnen Connect blokkeert het beleid (extern delen uit) al vóór de controller.
        $this->as($me)->postJson("/api/v1/missions/{$mission->id}/collaborators", ['user_id' => $stranger->id])->assertForbidden();

    }

    public function test_outside_connect_an_arbitrary_user_id_is_no_longer_instant_access(): void
    {
        $plain = $this->user('Gewoon'); $stranger = $this->user('Vreemde');
        $mission = Mission::create(['owner_id' => $plain->id, 'name' => 'P']);

        $this->as($plain)->postJson("/api/v1/missions/{$mission->id}/collaborators", ['user_id' => $stranger->id])->assertStatus(422);
        $this->assertFalse(MissionCollaborator::where('mission_id', $mission->id)->where('user_id', $stranger->id)->exists());
    }

    public function test_a_username_hit_becomes_an_invitation_the_other_must_accept(): void
    {
        $me = $this->user('Ik'); $stranger = $this->user('Publiek', 'Persoon', 'publiek@elders.test');
        $this->profile($stranger, 'publiekpersoon');
        $mission = Mission::create(['owner_id' => $me->id, 'name' => 'M']);

        $this->as($me)->postJson("/api/v1/missions/{$mission->id}/collaborators", ['user_id' => $stranger->id, 'role' => 'viewer'])
            ->assertCreated()->assertJsonPath('invitation.status', 'pending')->assertJsonPath('invitation.role', 'viewer');
        $this->assertFalse(MissionCollaborator::where('mission_id', $mission->id)->where('user_id', $stranger->id)->exists());
        $this->assertDatabaseHas('invitations', ['email' => 'publiek@elders.test', 'invited_user_id' => $stranger->id, 'status' => 'pending']);
    }

    public function test_email_invitations_keep_working(): void
    {
        $me = $this->user('Ik');
        $mission = Mission::create(['owner_id' => $me->id, 'name' => 'M']);

        $this->as($me)->postJson("/api/v1/missions/{$mission->id}/collaborators", ['email' => 'nieuw@elders.test', 'role' => 'editor'])
            ->assertCreated()->assertJsonPath('invitation.is_new_user', true);
    }
}
