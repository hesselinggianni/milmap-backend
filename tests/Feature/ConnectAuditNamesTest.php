<?php
namespace Tests\Feature;
use App\Models\{ConnectOrganization, ConnectMember, ConnectAllowedDevice, ConnectAuditEvent, Map, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
// Beheerhistorie in het Connect-portaal: namen i.p.v. kale account-/apparaatnummers.
class ConnectAuditNamesTest extends TestCase {
    use RefreshDatabase;
    public function test_audit_events_carry_names_instead_of_bare_ids(): void {
        Mail::fake();
        $org = ConnectOrganization::create(['name' => 'Organisatie', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), 'seat_limit' => 10]);
        $admin = User::create(['first_name' => 'Ad', 'last_name' => 'Min', 'email' => Str::uuid().'@example.org', 'password' => bcrypt('Password123!'), 'email_verified_at' => now()]);
        ConnectMember::create(['organization_id' => $org->id, 'user_id' => $admin->id, 'email' => $admin->email, 'first_name' => 'Anna', 'last_name' => 'Beheer', 'role' => 'owner', 'status' => 'active']);
        $device = ConnectAllowedDevice::create(['organization_id' => $org->id, 'name' => 'iPad Klas 1', 'platform' => 'ios', 'udid' => strtoupper(Str::random(8)).'-123456789']);
        $map = Map::create(['owner_id' => $admin->id, 'title' => 'Oefenkaart', 'status' => 'active']);
        $map->forceFill(['connect_organization_id' => $org->id])->save();

        ConnectAuditEvent::create(['organization_id' => $org->id, 'actor_user_id' => $admin->id, 'device_id' => $device->id, 'action' => 'PUT api/v1/maps/{mapId}', 'resource_type' => 'Map', 'resource_id' => $map->id, 'details' => []]);
        ConnectAuditEvent::create(['organization_id' => $org->id, 'actor_user_id' => $admin->id, 'device_id' => 999999, 'action' => 'device.assignment.updated', 'resource_type' => 'ConnectAllowedDevice', 'resource_id' => (string) $device->id, 'details' => ['device_name' => 'Oude iPad']]);
        ConnectAuditEvent::create(['organization_id' => $org->id, 'actor_user_id' => null, 'device_id' => null, 'action' => 'resources.transferred', 'resource_type' => null, 'resource_id' => null, 'details' => []]);

        Sanctum::actingAs($admin);
        $rows = $this->getJson('/api/v1/connect/organizations/'.$org->id.'/audit')->assertOk()->json('data');

        [$system, $deviceEvent, $mapEvent] = $rows; // nieuwste eerst
        $this->assertSame('Anna Beheer', $mapEvent['actor_name']); // naam uit het lidmaatschap
        $this->assertSame('iPad Klas 1', $mapEvent['device_name']);
        $this->assertSame('Oefenkaart', $mapEvent['resource_label']);
        $this->assertSame('Oude iPad', $deviceEvent['device_name']); // verwijderd apparaat: naam uit de details
        $this->assertSame('iPad Klas 1', $deviceEvent['resource_label']);
        $this->assertNull($system['actor_name']);
        $this->assertNull($system['resource_label']);
    }
}
