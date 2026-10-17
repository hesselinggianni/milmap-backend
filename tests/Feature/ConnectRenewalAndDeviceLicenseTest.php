<?php
namespace Tests\Feature;

use App\Mail\ConnectRenewalReminder;
use App\Models\{AppNotification, ConnectAllowedDevice, ConnectMember, ConnectOrganization, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConnectRenewalAndDeviceLicenseTest extends TestCase
{
    use RefreshDatabase;

    private function org(int $seats, int $daysLeft): array
    {
        $org = ConnectOrganization::create(['name' => 'Org', 'status' => 'active', 'seat_limit' => $seats, 'contact_email' => 'c@example.com']);
        $org->licenses()->create(['name' => 'L', 'seat_count' => $seats, 'monthly_price_cents' => 0, 'currency' => 'EUR', 'starts_at' => now()->subDays(300), 'ends_at' => now()->addDays($daysLeft)]);
        $owner = User::create(['first_name' => 'T', 'last_name' => 'U', 'email' => \Illuminate\Support\Str::uuid().'@example.org', 'password' => bcrypt('Password123!'), 'email_verified_at' => now()]);
        ConnectMember::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'first_name' => 'O', 'last_name' => 'W', 'email' => $owner->email, 'role' => 'owner', 'status' => 'active']);
        return [$org->fresh(), $owner];
    }

    public function test_devices_beyond_licensed_capacity_are_unlicensed(): void
    {
        [$org] = $this->org(2, 100); // 1 account + 1 vrije plek
        foreach (['AAAAAAAA-1', 'BBBBBBBB-2'] as $i => $u) ConnectAllowedDevice::create(['organization_id' => $org->id, 'name' => "iPad $i", 'udid' => $u, 'platform' => 'ios']);
        $this->assertSame([true, false], array_values($org->deviceLicenseStatus()['allowed']));
    }

    public function test_reminder_is_sent_once_with_notification(): void
    {
        Mail::fake();
        [, $owner] = $this->org(5, 20);
        $this->artisan('connect:renewal-reminders')->assertSuccessful();
        $this->artisan('connect:renewal-reminders')->assertSuccessful();
        Mail::assertSent(ConnectRenewalReminder::class, 2); // eigenaar + contactadres, één ronde
        $this->assertSame(1, AppNotification::where('user_id', $owner->id)->where('type', 'connect.renewal')->count());
    }

    public function test_no_reminder_when_expiry_is_far_away(): void
    {
        Mail::fake();
        $this->org(5, 90);
        $this->artisan('connect:renewal-reminders')->assertSuccessful();
        Mail::assertNothingSent();
    }
}
