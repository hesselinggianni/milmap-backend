<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CorsAndHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('api')->get('/api/__probe', fn () => response()->json(['ok' => true]));
    }

    /** Laadt config/cors.php opnieuw alsof APP_ENV de gegeven waarde heeft. */
    private function corsConfigFor(string $env): array
    {
        $old = getenv('APP_ENV');
        putenv("APP_ENV={$env}"); $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $env;
        try {
            return require base_path('config/cors.php');
        } finally {
            putenv("APP_ENV={$old}"); $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $old;
        }
    }

    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/__probe', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
    }

    public function test_production_cors_allows_app_origins_only(): void
    {
        config(['cors' => $this->corsConfigFor('production')]);

        foreach (['https://localhost', 'capacitor://localhost', 'https://app.milmap.nl', 'https://milmap.nl'] as $ok) {
            $this->assertSame($ok, $this->preflight($ok)->headers->get('Access-Control-Allow-Origin'), $ok);
        }
        foreach (['http://localhost:8080', 'http://localhost', 'https://localhost:3000', 'http://127.0.0.1:5173',
                  'https://evilmilmap.nl', 'https://milmap.nl.evil.com', 'https://localhost.evil.com'] as $bad) {
            $this->assertNull($this->preflight($bad)->headers->get('Access-Control-Allow-Origin'), $bad);
        }
    }

    public function test_non_production_cors_still_allows_local_dev(): void
    {
        config(['cors' => $this->corsConfigFor('local')]);

        $this->assertSame('http://localhost:8080', $this->preflight('http://localhost:8080')->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('https://localhost', $this->preflight('https://localhost')->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_security_headers_are_set(): void
    {
        $res = $this->getJson('/api/__probe');
        $res->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertFalse($res->headers->has('Strict-Transport-Security'));

        $this->get('https://localhost/api/__probe')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_native_app_source_url_gets_readable_label(): void
    {
        $cases = [
            'https://localhost/'             => 'android-app',
            'https://localhost/start?x=1'    => 'android-app /start',
            'capacitor://localhost/register' => 'ios-app /register',
            'http://localhost:8080/start'    => 'http://localhost:8080/start',
            'https://localhost.evil.com/'    => 'https://localhost.evil.com/',
            'https://milmap.nl/start'        => 'https://milmap.nl/start',
            'android-app /start'             => 'android-app /start',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, RegisterController::labelSourceUrl($in), $in);
        }
        $this->assertNull(RegisterController::labelSourceUrl(null));
    }
}
