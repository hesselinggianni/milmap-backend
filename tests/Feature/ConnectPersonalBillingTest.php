<?php
namespace Tests\Feature;

use App\Http\Middleware\EnforceConnectManagement;
use App\Models\{ConnectAllowedDevice, ConnectMember, ConnectOrganization, User};
use App\Services\{ConnectDeviceLogin, ConnectPersonalBillingGuard};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Log, Mail};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Connect: door een organisatie beheerde accounts (apparaataccounts
 * @connect.milmap.nl en actieve organisatieleden) komen nooit in de
 * persoonlijke Stripe-omgeving en krijgen nooit een Stripe-customer.
 */
class ConnectPersonalBillingTest extends TestCase
{
    use RefreshDatabase;

    private ConnectOrganization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Nooit echt met Stripe praten: zonder secret en price-ID's stopt elke
        // niet-geblokkeerde aanroep vóór Stripe (422 "Ongeldig plan" / 404).
        config(['billing.stripe_secret' => null, 'billing.stripe_price_pro_monthly' => null, 'billing.stripe_price_pro_yearly' => null,
            'billing.stripe_price_team_monthly' => null, 'billing.stripe_price_team_yearly' => null]);
        $this->org = ConnectOrganization::create(['name' => 'Organisation', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), 'seat_limit' => 10]);
    }

    private function user(?string $email = null): User
    {
        return User::create(['first_name' => 'Test', 'last_name' => 'User', 'email' => $email ?? Str::uuid().'@example.org', 'password' => bcrypt('Password123!'), 'email_verified_at' => now()]);
    }

    private function member(string $status = 'active', string $role = 'member'): User
    {
        $user = $this->user();
        ConnectMember::create(['organization_id' => $this->org->id, 'user_id' => $user->id, 'email' => $user->email, 'first_name' => 'Member', 'last_name' => 'Person', 'role' => $role, 'status' => $status]);

        return $user;
    }

    private function deviceToken(): array
    {
        $d = ConnectAllowedDevice::create(['organization_id' => $this->org->id, 'name' => 'Tablet', 'platform' => 'ios', 'udid' => strtoupper(Str::random(8)).'-123456789']);
        $binding = app(ConnectDeviceLogin::class)->provision($d);
        auth()->forgetGuards();
        $res = $this->withHeaders(['Authorization' => '', 'X-Milmap-Installation' => (string) Str::uuid()])->postJson('/api/v1/connect/device-login', ['device_binding' => $binding])->assertOk();

        return [$d->fresh(), $res->json('token')];
    }

    private function personalBillingCalls(): array
    {
        return [
            ['postJson', '/api/v1/billing/checkout', ['plan' => 'pro_monthly']],
            ['postJson', '/api/v1/billing/portal', []],
            ['postJson', '/api/v1/billing/cancel', []],
            ['postJson', '/api/v1/billing/resume', []],
            ['postJson', '/api/v1/billing/change-plan', ['plan' => 'pro_yearly']],
            ['getJson', '/api/v1/billing/subscription', []],
            ['getJson', '/api/v1/billing/invoices', []],
            ['getJson', '/api/v1/billing/plans', []],
            ['getJson', '/api/v1/billing/session?session_id=cs_test_x', []],
        ];
    }

    public function test_device_account_never_reaches_personal_billing(): void
    {
        [$device, $token] = $this->deviceToken();
        auth()->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);
        foreach ($this->personalBillingCalls() as [$method, $path, $body]) {
            $this->{$method}($path, $body)->assertForbidden()->assertJsonPath('code', 'connect_policy_denied');
        }
        $user = User::find($device->user_id);
        $this->assertStringEndsWith('@connect.milmap.nl', $user->email);
        $this->assertNull($user->stripe_id);
    }

    public function test_active_member_is_blocked_and_former_member_gets_personal_billing_back(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);
        foreach ($this->personalBillingCalls() as [$method, $path, $body]) {
            $this->{$method}($path, $body)->assertForbidden()->assertJsonPath('code', 'connect_policy_denied');
        }
        $this->assertNull($user->fresh()->stripe_id);

        // Vertrokken lid: weer een gewoon persoonlijk account. De aanroep komt
        // nu langs de Connect-poort en stopt pas bij de (lege) prijsconfig.
        ConnectMember::where('user_id', $user->id)->update(['status' => 'removed']);
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'pro_monthly'])->assertStatus(422)->assertJsonPath('message', 'Ongeldig plan geselecteerd.');
    }

    public function test_organisation_owner_cannot_use_personal_billing_either(): void
    {
        Sanctum::actingAs($this->member('active', 'owner'));
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'pro_monthly'])->assertForbidden()->assertJsonPath('code', 'connect_policy_denied');
        $this->postJson('/api/v1/billing/portal')->assertForbidden();
    }

    public function test_controller_guard_blocks_even_without_the_enforce_middleware(): void
    {
        $this->withoutMiddleware(EnforceConnectManagement::class);
        Sanctum::actingAs($this->member());
        foreach ($this->personalBillingCalls() as [$method, $path, $body]) {
            $this->{$method}($path, $body)->assertForbidden()->assertJsonPath('permission', 'personal_billing');
        }

        // Gewoon account: de controllerlaag laat 'm door (geen Stripe-customer → 404).
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/billing/portal')->assertNotFound();
    }

    public function test_guest_checkout_refuses_managed_and_device_emails_without_creating_a_customer(): void
    {
        $member = $this->member();
        foreach ([strtoupper($member->email), 'abc123-device@connect.milmap.nl', 'X@CONNECT.MILMAP.NL'] as $email) {
            $this->postJson('/api/v1/billing/guest-checkout', ['email' => $email, 'plan' => 'pro_monthly'])
                ->assertForbidden()->assertJsonPath('code', 'connect_policy_denied')->assertJsonPath('permission', 'personal_billing');
        }
        $this->assertNull($member->fresh()->stripe_id);
        $this->assertDatabaseMissing('users', ['email' => 'abc123-device@connect.milmap.nl']);

        // Gewone (nieuwe) bezoeker: de poort laat 'm door; stopt bij de prijsconfig.
        $this->postJson('/api/v1/billing/guest-checkout', ['email' => 'visitor@example.org', 'plan' => 'pro_monthly'])->assertStatus(422);
    }

    public function test_appsumo_redeem_refuses_the_device_domain(): void
    {
        $this->postJson('/api/v1/appsumo/redeem', ['code' => 'ANYCODE', 'email' => 'tablet@connect.milmap.nl', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'])
            ->assertForbidden()->assertJsonPath('permission', 'personal_billing');
        $this->assertDatabaseMissing('users', ['email' => 'tablet@connect.milmap.nl']);
    }

    public function test_registration_skips_stripe_customer_for_people_joining_an_organisation(): void
    {
        $guard = app(ConnectPersonalBillingGuard::class);
        ConnectMember::create(['organization_id' => $this->org->id, 'email' => 'invited@example.org', 'first_name' => 'In', 'last_name' => 'Vited', 'role' => 'member', 'status' => 'pending']);
        ConnectMember::create(['organization_id' => $this->org->id, 'email' => 'left@example.org', 'first_name' => 'Le', 'last_name' => 'Ft', 'role' => 'member', 'status' => 'removed']);
        $this->assertTrue($guard->joiningOrganisation('Invited@Example.org'));
        $this->assertTrue($guard->joiningOrganisation('tablet@connect.milmap.nl'));
        $this->assertFalse($guard->joiningOrganisation('left@example.org'));
        $this->assertFalse($guard->joiningOrganisation('someone@example.org'));

        // Met een (nep)secret zou een niet-overgeslagen registratie Stripe
        // proberen te bereiken en bij falen een waarschuwing loggen.
        config(['billing.stripe_secret' => 'sk_test_dummy']);
        Log::spy();
        $this->postJson('/api/v1/register', ['email' => 'invited@example.org', 'first_name' => 'In', 'last_name' => 'Vited'])->assertCreated();
        $this->postJson('/api/v1/register', ['email' => 'owner@example.org', 'first_name' => 'Ow', 'last_name' => 'Ner', 'source_url' => 'https://connect.milmap.nl/start'])->assertCreated();
        Log::shouldNotHaveReceived('warning', [\Mockery::on(fn ($message) => str_contains((string) $message, 'Stripe-customer'))]);
        $this->assertNull(User::where('email', 'invited@example.org')->value('stripe_id'));
        $this->assertNull(User::where('email', 'owner@example.org')->value('stripe_id'));
    }
}
