<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Map;
use App\Models\AdminLoginCode;
use App\Policies\MapPolicy;
use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\PrivateApiResponse;
use App\Mail\AdminLoginCodeMail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

class SecurityHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Never migrate or truncate the developer/production database.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'hashing.driver' => 'bcrypt', 'hashing.bcrypt.rounds' => 4]);
        DB::purge('sqlite');
        Schema::create('permissions', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('guard_name'); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('email'); $t->boolean('is_admin')->default(false);
            $t->string('first_name')->nullable(); $t->string('last_name')->nullable(); $t->timestamps();
        });
        Schema::create('admin_login_codes', function (Blueprint $t) {
            $t->id(); $t->string('email'); $t->string('code')->unique(); $t->string('ip_address');
            $t->timestamp('expires_at'); $t->timestamp('used_at')->nullable(); $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64)->unique();
            $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
        Schema::create('maps', function (Blueprint $t) {
            $t->string('id')->primary(); $t->unsignedBigInteger('owner_id'); $t->string('title');
            $t->string('status')->default('active'); $t->json('settings')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('map_collaborators', function (Blueprint $t) {
            $t->id(); $t->string('map_id'); $t->unsignedBigInteger('user_id'); $t->string('role'); $t->string('status');
        });
        Schema::create('missions', function (Blueprint $t) {
            $t->string('id'); $t->unsignedBigInteger('owner_id'); $t->json('map')->nullable(); $t->softDeletes();
        });
        Schema::create('mission_collaborators', function (Blueprint $t) {
            $t->id(); $t->string('mission_id'); $t->unsignedBigInteger('user_id'); $t->string('role'); $t->string('status');
        });
        DB::table('users')->insert(['id' => 1, 'email' => 'admin@example.test', 'is_admin' => true]);
        Mail::fake();
    }

    public function test_viewer_cannot_edit_but_editor_can(): void
    {
        $map = new Map(['owner_id' => 99]); $map->id = 'map-a';
        $user = User::findOrFail(1);
        DB::table('map_collaborators')->insert(['map_id' => 'map-a', 'user_id' => 1, 'role' => 'viewer', 'status' => 'accepted']);
        $policy = new MapPolicy();
        $this->assertTrue($policy->view($user, $map));
        $this->assertFalse($policy->update($user, $map));
        $this->assertSame('viewer', $policy->roleFor($user, $map));
        DB::table('map_collaborators')->update(['role' => 'editor']);
        $this->assertTrue($policy->update($user, $map));
        DB::table('map_collaborators')->update(['status' => 'pending']);
        $this->assertFalse($policy->view($user, $map));
        $this->assertFalse($policy->update($user, $map));
    }

    public function test_request_is_generic_and_code_is_hashed(): void
    {
        $known = $this->postJson('/api/v1/admin/request-code', ['email' => 'admin@example.test'])->assertOk();
        $unknown = $this->postJson('/api/v1/admin/request-code', ['email' => 'nobody@example.test'])->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
        Mail::assertSent(AdminLoginCodeMail::class, function ($mail) {
            $stored = AdminLoginCode::firstOrFail()->code;
            $this->assertNotSame($mail->code, $stored);
            $this->assertTrue(Hash::check($mail->code, $stored));
            return true;
        });
    }

    public function test_code_is_one_time_and_token_expires(): void
    {
        $this->challenge('123456');
        $response = $this->postJson('/api/v1/admin/verify-code', ['email' => 'admin@example.test', 'code' => '123456'])->assertOk();
        $this->assertNotEmpty($response->json('token'));
        $token = User::findOrFail(1)->tokens()->firstOrFail();
        $this->assertTrue($token->expires_at->between(now()->addHours(7), now()->addHours(9)));
        $this->postJson('/api/v1/admin/verify-code', ['email' => 'admin@example.test', 'code' => '123456'])->assertUnauthorized();
        $this->assertSame(1, User::findOrFail(1)->tokens()->count());
    }

    public function test_plaintext_and_expired_codes_are_rejected(): void
    {
        $code = $this->challenge('123456');
        $code->update(['code' => '123456']);
        $this->postJson('/api/v1/admin/verify-code', ['email' => 'admin@example.test', 'code' => '123456'])->assertUnauthorized();
        $code->update(['code' => Hash::make('123456'), 'expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/admin/verify-code', ['email' => 'admin@example.test', 'code' => '123456'])->assertUnauthorized();
    }

    public function test_five_failed_attempts_block_even_correct_code(): void
    {
        $this->challenge('123456');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/verify-code', ['email' => 'admin@example.test', 'code' => '000000'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/admin/verify-code', ['email' => 'admin@example.test', 'code' => '123456'])->assertStatus(429);
    }

    public function test_admin_rejects_wildcard_and_unbounded_tokens(): void
    {
        $user = User::findOrFail(1);
        foreach ([['*'], ['user'], ['admin']] as $abilities) {
            $token = $user->createToken('legacy', $abilities)->accessToken;
            $this->actingAs($user->withAccessToken($token));
            $response = (new AdminAuth())->handle(Request::create('/'), fn () => response('ok'));
            $this->assertSame(403, $response->getStatusCode());
        }
        $token = $user->createToken('admin', ['admin'], now()->addHours(8))->accessToken;
        $this->actingAs($user->withAccessToken($token));
        $this->assertSame(200, (new AdminAuth())->handle(Request::create('/'), fn () => response('ok'))->getStatusCode());
        $this->assertSame(10080, config('sanctum.expiration'));
    }

    public function test_authenticated_response_cannot_be_publicly_cached(): void
    {
        $request = Request::create('/api/v1/maps', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer test']);
        $response = (new PrivateApiResponse())->handle($request, fn () => response('private data')->header('Cache-Control', 'public, max-age=3600'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function test_actual_map_update_denies_viewer_without_changing_data(): void
    {
        \Illuminate\Support\Facades\Route::put('/__security/maps/{id}', [\App\Http\Controllers\MapController::class, 'update']);
        DB::table('maps')->insert(['id' => 'map-a', 'owner_id' => 99, 'title' => 'Original']);
        DB::table('map_collaborators')->insert(['map_id' => 'map-a', 'user_id' => 1, 'role' => 'viewer', 'status' => 'accepted']);
        $this->actingAs(User::findOrFail(1));
        $this->putJson('/__security/maps/map-a', ['title' => 'Forbidden'])->assertForbidden();
        $this->assertSame('Original', DB::table('maps')->value('title'));
        DB::table('map_collaborators')->update(['role' => 'editor']);
        $this->putJson('/__security/maps/map-a', ['title' => 'Allowed'])->assertOk();
        $this->assertSame('Allowed', DB::table('maps')->value('title'));
    }

    public function test_sanctum_rejects_legacy_token_after_seven_days(): void
    {
        \Illuminate\Support\Facades\Route::get('/__security/session.json', fn () => response()->json(['ok' => true]))->middleware('auth:sanctum');
        $token = User::findOrFail(1)->createToken('legacy', ['user']);
        $token->accessToken->forceFill(['created_at' => now()->subDays(8)])->save();
        $this->withToken($token->plainTextToken)->getJson('/__security/session.json')->assertUnauthorized();
    }

    public function test_sensitive_email_is_not_archived_or_tracked(): void
    {
        $this->postJson('/api/v1/admin/request-code', ['email' => 'admin@example.test'])->assertOk();
        Mail::assertSent(AdminLoginCodeMail::class, function ($mail) {
            $message = (new \Symfony\Component\Mime\Email())->from('system@example.test')->to('admin@example.test')->html('secret');
            foreach ($mail->callbacks as $callback) $callback($message);
            $this->assertTrue($message->getHeaders()->has('X-Milmap-Sensitive'));
            DB::enableQueryLog();
            DB::flushQueryLog();
            event(new \Illuminate\Mail\Events\MessageSending($message));
            $this->assertSame([], DB::getQueryLog());
            $this->assertFalse($message->getHeaders()->has('X-MilMap-Track'));
            return true;
        });
    }

    private function challenge(string $code): AdminLoginCode
    {
        return AdminLoginCode::create(['email' => 'admin@example.test', 'code' => Hash::make($code),
            'ip_address' => '127.0.0.1', 'expires_at' => now()->addMinutes(15)]);
    }
}
