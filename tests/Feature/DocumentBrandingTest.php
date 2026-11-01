<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Huisstijl voor papieren (Account → Documenten): eigen logo (GET/POST/DELETE
 * /user/document-logo, /user/document-branding) en MilMap-branding uit via
 * PUT /user/settings { settings: { document_hide_branding } }.
 */
class DocumentBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['first_name' => 'Test', 'last_name' => 'Gebruiker', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now()]);
    }

    private function as(User $user): self
    {
        app('auth')->forgetGuards();
        return $this->withToken($user->createToken('test', ['user'], now()->addHour())->plainTextToken);
    }

    /** Kleine echte PNG (transparante achtergrond), zonder GD nodig te hebben. */
    private function png(int $w = 40, int $h = 12): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
        imagepng($img, $path);
        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }

    public function test_default_is_no_logo_and_branding_on(): void
    {
        $this->as($this->user())->getJson('/api/v1/user/document-branding')
            ->assertOk()
            ->assertExactJson(['hide_branding' => false, 'logo' => null]);
    }

    public function test_upload_returns_logo_as_data_url_and_replace_and_delete_work(): void
    {
        Storage::fake('public');
        $user = $this->user();

        $res = $this->as($user)->postJson('/api/v1/user/document-logo', ['logo' => $this->png(40, 12)])->assertOk();
        $res->assertJsonPath('logo.width', 40)->assertJsonPath('logo.height', 12);
        $this->assertStringStartsWith('data:image/png;base64,', $res->json('logo.data_url'));
        Storage::disk('public')->assertExists("document-logos/{$user->id}/logo.png");

        // Vervangen: zelfde plek, nieuwe afmetingen.
        $this->as($user)->postJson('/api/v1/user/document-logo', ['logo' => $this->png(60, 20)])
            ->assertOk()->assertJsonPath('logo.width', 60);

        $this->as($user)->deleteJson('/api/v1/user/document-logo')->assertOk()->assertJsonPath('logo', null);
        Storage::disk('public')->assertMissing("document-logos/{$user->id}/logo.png");
    }

    public function test_only_png_images_are_accepted(): void
    {
        Storage::fake('public');
        $user = $this->user();

        $this->as($user)->postJson('/api/v1/user/document-logo', ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])
            ->assertStatus(422);
        $this->as($user)->postJson('/api/v1/user/document-logo', ['logo' => UploadedFile::fake()->image('logo.jpg', 50, 20)])
            ->assertStatus(422);
        $this->as($user)->postJson('/api/v1/user/document-logo', [])->assertStatus(422);
    }

    public function test_logos_are_per_user(): void
    {
        Storage::fake('public');
        $a = $this->user();
        $b = $this->user();

        $this->as($a)->postJson('/api/v1/user/document-logo', ['logo' => $this->png()])->assertOk();

        $this->as($b)->getJson('/api/v1/user/document-branding')->assertOk()->assertJsonPath('logo', null);
        // Een eigen (gemanipuleerde) settings-sleutel kan niet naar andermans bestand wijzen.
        $this->as($b)->putJson('/api/v1/user/settings', ['settings' => ['document_logo_path' => "document-logos/{$a->id}/logo.png"]])->assertOk();
        $this->as($b)->getJson('/api/v1/user/document-branding')->assertOk()->assertJsonPath('logo', null);
    }

    public function test_hide_branding_is_stored_as_a_real_boolean_and_survives_other_settings(): void
    {
        $user = $this->user();

        $this->as($user)->putJson('/api/v1/user/settings', ['settings' => ['document_hide_branding' => true]])->assertOk();
        $this->as($user)->getJson('/api/v1/user/document-branding')->assertJsonPath('hide_branding', true);
        $this->assertSame(true, $user->fresh()->settings['document_hide_branding']);

        // Andere voorkeuren laten de keuze met rust.
        $this->as($user)->putJson('/api/v1/user/settings', ['settings' => ['units' => 'km']])->assertOk();
        $this->as($user)->getJson('/api/v1/user/document-branding')->assertJsonPath('hide_branding', true);

        $this->as($user)->putJson('/api/v1/user/settings', ['settings' => ['document_hide_branding' => false]])->assertOk();
        $this->as($user)->getJson('/api/v1/user/document-branding')->assertJsonPath('hide_branding', false);
    }

    public function test_requires_login(): void
    {
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/user/document-branding')->assertStatus(401);
        $this->postJson('/api/v1/user/document-logo', [])->assertStatus(401);
    }
}
