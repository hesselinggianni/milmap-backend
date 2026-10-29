<?php
namespace Tests\Unit;

use App\Http\Controllers\ClientErrorController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Het foutenoverzicht is voor kapotte dingen: verwacht gedrag en goede
 * meetwaarden horen er niet in (oudere app-builds sturen ze nog wel).
 */
class ClientErrorRuisTest extends TestCase
{
    private function isRuis(array $data): bool
    {
        $m = new ReflectionMethod(ClientErrorController::class, 'isRuis');
        $m->setAccessible(true);

        return $m->invoke(new ClientErrorController(), $data);
    }

    private function perf(string $message): array
    {
        return ['type' => 'perf_diag', 'message' => $message];
    }

    public function test_goede_navigatie_performance_wordt_niet_opgeslagen(): void
    {
        $this->assertTrue($this->isRuis($this->perf('nav perf #6: 59fps, 17 jank, 15 gps-ticks, 15 animate/0 setCenter')));
        $this->assertTrue($this->isRuis($this->perf('nav perf #3: 56fps, 34 jank, 15 gps-ticks, 9 animate/0 setCenter')));
        $this->assertTrue($this->isRuis($this->perf('nav perf #1: 58fps, 12 jank (0 zwaar), 1 gps-ticks, 1 animate/0 setCenter')));
        // Gedrosseld op ~30fps (oudere builds telden dan elk frame als jank).
        $this->assertTrue($this->isRuis($this->perf('nav perf #1: 30fps, 160 jank, 0 gps-ticks, 0 animate/0 setCenter')));
    }

    public function test_slechte_navigatie_performance_blijft_staan(): void
    {
        $this->assertFalse($this->isRuis($this->perf('nav perf #2: 39fps, 117 jank, 15 gps-ticks, 14 animate/0 setCenter')));
        $this->assertFalse($this->isRuis($this->perf('nav perf #3: 19fps, 102 jank, 3 gps-ticks, 3 animate/0 setCenter')));
        $this->assertFalse($this->isRuis($this->perf('nav perf #1: 52fps, 120 jank, 10 gps-ticks, 6 animate/1 setCenter')), 'jank > 10% van de frames');
        $this->assertFalse($this->isRuis($this->perf('nav perf #1: 58fps, 12 jank (4 zwaar), 1 gps-ticks, 1 animate/0 setCenter')), 'herhaalde zware stotters');
        $this->assertFalse($this->isRuis($this->perf('onleesbaar bericht')), 'wat we niet kunnen lezen filteren we niet weg');
    }

    public function test_locatie_omstandigheden_zijn_geen_defect(): void
    {
        foreach ([
            'locatiestream hersteld na OS-PLUG-GLOC-0002 (eerste positie na 8957 s)',
            'checkPermissions/requestPermissions gooide een fout — Location services are not enabled.',
            'getCurrentPosition mislukt — code=OS-PLUG-GLOC-0003 message=Location permission request was denied.',
            'getCurrentPosition mislukt — code=OS-PLUG-GLOC-0002 message=There was en error trying to obtain the location.',
        ] as $message) {
            $this->assertTrue($this->isRuis(['type' => 'location_diag', 'message' => $message]), $message);
        }

        foreach ([
            'watchPosition mislukt — code=OS-PLUG-GLOC-0002 message=x',
            'locatiestream herstart leverde geen positie op (watchPosition: code=OS-PLUG-GLOC-0002, 12 s na de herstart)',
            'geolocation-shim volledig mislukt (webview valt terug) — boom',
        ] as $message) {
            $this->assertFalse($this->isRuis(['type' => 'location_diag', 'message' => $message]), $message);
        }
    }

    public function test_horloge_annulering_is_geen_fout(): void
    {
        $this->assertTrue($this->isRuis(['type' => 'watch_diag', 'message' => 'kaarten: mislukt — cancelled']));
        $this->assertFalse($this->isRuis(['type' => 'watch_diag', 'message' => 'kaarten: mislukt — The request timed out.']));
        $this->assertFalse($this->isRuis(['type' => 'watch_diag', 'message' => 'kaarten: 3 opgehaald']));
    }

    public function test_ontbrekende_kaart_of_missie_bij_lezen_is_geen_fout(): void
    {
        $base = ['type' => 'api_error', 'status' => 404, 'method' => 'GET', 'endpoint' => '/api/v1/missions/b5fd', 'message' => 'GET /api/v1/missions/b5fd → 404'];
        $mission = '{"message":"No query results for model [App\\\\Models\\\\Mission] b5fd"}';

        $this->assertTrue($this->isRuis($base + ['response' => $mission]));
        $this->assertFalse($this->isRuis(array_merge($base, ['method' => 'PUT', 'response' => $mission])), 'schrijfactie blijft gemeld');
        $this->assertFalse($this->isRuis($base + ['response' => '{"message":"No query results for model [App\\\\Models\\\\Report] 1"}']));
    }

    public function test_scripts_die_niet_van_ons_zijn_worden_niet_opgeslagen(): void
    {
        $geinjecteerd = "Dh@\nCh@\nanonymous@\n@https://app.milmap.nl/register:415:45";
        $leeg = "@\nNi@\n@\nPromise@[native code]\nQi@";
        $eigen = "t@https://app.milmap.nl/js/app.a2a35968.js:1:2345\nPromise@[native code]";

        $this->assertTrue($this->isRuis(['type' => 'js_error', 'message' => 'Error: qa', 'stack' => $geinjecteerd]));
        $this->assertTrue($this->isRuis(['type' => 'unhandled_rejection', 'message' => 'Ma', 'stack' => $leeg]));
        $this->assertFalse($this->isRuis(['type' => 'unhandled_rejection', 'message' => 'x is undefined', 'stack' => $eigen]));
        $this->assertFalse($this->isRuis(['type' => 'js_error', 'message' => 'Script error']), 'zonder stack niet filteren');
    }
}
