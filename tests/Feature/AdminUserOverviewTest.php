<?php
namespace Tests\Feature;

use App\Models\{User, Map, MailAccount};
use App\Services\MailboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUserOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin = false): User
    {
        $user = User::create(['first_name' => 'Test', 'last_name' => 'Account', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now()]);
        $user->forceFill(['is_admin' => $admin])->save();
        return $user;
    }

    private function login(User $user): void
    {
        app('auth')->forgetGuards();
        $token = $user->createToken('test', ['admin'], now()->addHour());
        $this->withToken($token->plainTextToken);
    }

    public function test_dashboard_identifies_connected_ipad_with_readable_hardware_info(): void
    {
        $customer = $this->user();
        $device = \App\Models\ConnectDevice::create([
            'user_id' => $customer->id, 'installation_id' => (string) Str::uuid(),
            'platform' => 'ios', 'name' => 'iPad van Gianni', 'model' => 'iPad13,8',
            'os_version' => '26.6.1', 'app_version' => '1.28', 'last_seen_at' => now(),
        ]);
        $token = $customer->createToken('iPad')->accessToken;
        $token->forceFill(['connect_device_id' => $device->id])->save();
        $response = app(\App\Http\Controllers\AdminController::class)->getDashboardStats();
        $this->assertSame(200, $response->getStatusCode());
        $mobile = collect($response->getData(true)['users'])->firstWhere('id', $customer->id)['mobile_devices'][0];
        $this->assertSame('iPad van Gianni', $mobile['name']);
        $this->assertSame('iPad Pro 12.9" (5e gen)', $mobile['model']);
        $this->assertSame('26.6.1', $mobile['os_version']);
        $this->assertSame('1.28', $mobile['app_version']);
        $this->assertSame($device->installation_id, $mobile['installation_id']);
    }

    public function test_regular_users_cannot_access_customer_overview(): void
    {
        $user = $this->user(); $this->login($user);
        $this->getJson("/api/v1/admin/users/{$user->id}/invoices")->assertForbidden();
    }

    public function test_admin_cannot_read_customer_workspace(): void
    {
        $admin = $this->user(true); $customer = $this->user();
        Map::create(['owner_id' => $customer->id, 'title' => 'Customer map', 'status' => 'active']);
        $this->login($admin);
        $this->getJson("/api/v1/admin/users/{$customer->id}/workspace?section=maps")->assertNotFound();
    }

    public function test_customer_without_stripe_has_empty_invoice_list(): void
    {
        $admin = $this->user(true); $customer = $this->user(); $this->login($admin);
        $this->getJson("/api/v1/admin/users/{$customer->id}/invoices")->assertOk()->assertJsonPath('invoices', [])->assertJsonPath('has_more', false);
    }

    public function test_mail_move_is_scoped_to_mailbox_owner(): void
    {
        $admin = $this->user(true); $other = $this->user(true);
        $box = new MailAccount();
        $box->forceFill(['id' => (string) Str::uuid(), 'user_id' => $admin->id, 'name' => 'Test', 'email' => 'mail@example.test', 'imap_host' => 'imap.example.test', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_username' => 'test', 'imap_password' => 'secret', 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => 'test', 'smtp_password' => 'secret'])->save();
        $mock = $this->mock(MailboxService::class);
        $mock->shouldReceive('moveMessage')->once()->withArgs(fn ($account, $folder, $uid, $destination) => $account->id === $box->id && $folder === 'INBOX' && $uid === 7 && $destination === 'Trash');
        $this->login($admin);
        $this->postJson("/api/v1/admin/mail/accounts/{$box->id}/messages/7/move", ['folder' => 'INBOX', 'destination' => 'Trash'])->assertOk();
        $this->postJson("/api/v1/admin/mail/accounts/{$box->id}/messages/7/move", ['folder' => 'INBOX', 'destination' => 'INBOX'])->assertUnprocessable();
        $this->login($other);
        $this->postJson("/api/v1/admin/mail/accounts/{$box->id}/messages/7/move", ['folder' => 'INBOX', 'destination' => 'Trash'])->assertNotFound();
    }
    public function test_admin_can_convert_existing_user_to_connect_owner_once(): void
    {
        $admin = $this->user(true); $customer = $this->user(); $this->login($admin);
        $payload = ['name' => 'Voorbeeldorganisatie', 'seats' => 10, 'monthly_price' => 49.50, 'months' => 12];
        $this->postJson("/api/v1/admin/users/{$customer->id}/connect-organization", $payload)
            ->assertCreated()->assertJsonPath('organization.monthly_price_cents', 4950)->assertJsonPath('organization.seat_limit', 10);
        $this->assertDatabaseHas('connect_members', ['user_id' => $customer->id, 'role' => 'owner', 'status' => 'active']);
        $this->assertTrue($customer->fresh()->hasPremiumAccess());
        $this->getJson("/api/v1/admin/users/{$customer->id}")->assertOk()->assertJsonCount(1, 'connect_organizations');
        $this->postJson("/api/v1/admin/users/{$customer->id}/connect-organization", $payload)->assertUnprocessable();
        $this->assertDatabaseCount('connect_organizations', 1);
    }

    public function test_licenses_stack_and_expire_independently_without_duplicate_retry(): void
    {
        $this->travelTo(now()->startOfSecond());
        $admin = $this->user(true); $customer = $this->user(); $this->login($admin);
        $this->postJson("/api/v1/admin/users/{$customer->id}/connect-organization", ['name'=>'School','seats'=>50,'monthly_price'=>100,'months'=>1])->assertCreated();
        $org = \App\Models\ConnectOrganization::first();
        $this->travel(15)->days();
        $payload = ['name'=>'Tweede levering','seats'=>50,'monthly_price'=>120,'months'=>2,'request_id'=>(string) \Illuminate\Support\Str::uuid()];
        $url = "/api/v1/admin/users/{$customer->id}/connect-organizations/{$org->id}/licenses";
        $this->login($admin);
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('organization.seat_limit', 100)->assertJsonPath('organization.monthly_price_cents', 22000);
        $this->postJson($url, $payload)->assertCreated();
        $this->assertDatabaseCount('connect_licenses', 2);
        $other = $this->user();
        $this->postJson("/api/v1/admin/users/{$other->id}/connect-organizations/{$org->id}/licenses", $payload)->assertNotFound();
        $this->travel(20)->days();
        $this->assertSame(50, $org->fresh()->seat_limit);
        $this->assertSame(12000, $org->fresh()->monthly_price_cents);
        $this->assertTrue($customer->fresh()->hasPremiumAccess());
        $this->travel(3)->months();
        $this->assertSame(0, $org->fresh()->seat_limit);
        $this->assertFalse($customer->fresh()->hasPremiumAccess());
    }

}
