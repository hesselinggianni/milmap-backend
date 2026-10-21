<?php
namespace Tests\Feature;

use App\Models\Mission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Een video voor een presentatiepagina gaat via dezelfde route als een O-group-bijlage
 * (POST /missions/{id}/ogroup/upload), met een eigen (hogere) limiet en alleen echte videotypes.
 */
class OgroupVideoUploadTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create(['first_name' => 'Eigenaar', 'last_name' => 'Test', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now()]);
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();
        return $this->withToken($user->createToken('test', ['user'], now()->addHour())->plainTextToken);
    }

    public function test_video_up_to_100_mb_is_stored_and_returned(): void
    {
        Storage::fake('public');
        $owner = $this->owner();
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Missie']);

        $res = $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/ogroup/upload", [
            'file' => UploadedFile::fake()->create('briefing.mp4', 50 * 1024, 'video/mp4'),
        ])->assertCreated();

        $res->assertJsonPath('name', 'briefing.mp4');
        $this->assertStringEndsWith('.mp4', $res->json('url'));
        $this->assertCount(1, Storage::disk('public')->files("ogroup/{$mission->id}"));
        $this->assertDatabaseHas('user_uploads', ['user_id' => $owner->id, 'kind' => 'ogroup', 'mime' => 'video/mp4']);
    }

    public function test_webm_and_mov_are_allowed(): void
    {
        Storage::fake('public');
        $owner = $this->owner();
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Missie']);
        $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/ogroup/upload", ['file' => UploadedFile::fake()->create('a.webm', 100, 'video/webm')])->assertCreated();
        $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/ogroup/upload", ['file' => UploadedFile::fake()->create('b.mov', 100, 'video/quicktime')])->assertCreated();
    }

    public function test_video_over_100_mb_and_other_files_over_10_mb_are_refused(): void
    {
        Storage::fake('public');
        $owner = $this->owner();
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Missie']);
        $url = "/api/v1/missions/{$mission->id}/ogroup/upload";

        $this->api($owner)->postJson($url, ['file' => UploadedFile::fake()->create('groot.mp4', 100 * 1024 + 1, 'video/mp4')])->assertUnprocessable();
        // De limiet voor gewone bijlagen blijft 10 MB.
        $this->api($owner)->postJson($url, ['file' => UploadedFile::fake()->create('groot.pdf', 11 * 1024, 'application/pdf')])->assertUnprocessable();
        $this->api($owner)->postJson($url, ['file' => UploadedFile::fake()->create('klein.pdf', 1024, 'application/pdf')])->assertCreated();
    }

    public function test_video_extension_with_a_non_video_type_is_refused(): void
    {
        Storage::fake('public');
        $owner = $this->owner();
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Missie']);
        $this->api($owner)->postJson("/api/v1/missions/{$mission->id}/ogroup/upload", ['file' => UploadedFile::fake()->create('nep.mp4', 10, 'text/html')])->assertUnprocessable();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_only_editors_can_upload(): void
    {
        Storage::fake('public');
        $owner = $this->owner();
        $mission = Mission::create(['owner_id' => $owner->id, 'name' => 'Missie']);
        $this->api($this->owner())->postJson("/api/v1/missions/{$mission->id}/ogroup/upload", ['file' => UploadedFile::fake()->create('a.mp4', 10, 'video/mp4')])->assertForbidden();
    }
}
