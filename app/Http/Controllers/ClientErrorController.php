<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClientErrorController extends Controller
{
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'type'    => 'required|string|max:50',
            'message' => 'required|string|max:2000',
            'source'  => 'nullable|string|max:500',
            'lineno'  => 'nullable|integer',
            'colno'   => 'nullable|integer',
            'stack'   => 'nullable|string|max:8000',
            'info'    => 'nullable|string|max:500',
            'url'     => 'nullable|string|max:500',
            // Zonder deze context is een melding uit de app nauwelijks te
            // herleiden: op welk toestel, welke build, en bij een mislukte
            // API-aanroep welk endpoint met welke status/antwoord.
            'platform'    => 'nullable|string|max:20',
            'app_version' => 'nullable|string|max:30',
            'status'      => 'nullable|integer',
            'method'      => 'nullable|string|max:10',
            'endpoint'    => 'nullable|string|max:500',
            'response'    => 'nullable|string|max:2000',
        ]);

        // Ruis niet opslaan: het foutenoverzicht is bedoeld om kapotte dingen
        // te vinden, en verwachte uitkomsten (diagnostiek, een geweigerde
        // toestemming, een rate-limit) verdrongen daar de echte fouten. Oude
        // app-builds blijven dit sturen, dus het filter hoort hier en niet
        // alleen in de front-end.
        if ($this->isRuis($data)) {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        // Also log to laravel.log for visibility
        Log::warning('[client-error] ' . $data['message'], [
            'type'     => $data['type'],
            'source'   => $data['source'] ?? null,
            'line'     => $data['lineno'] ?? null,
            'url'      => $data['url'] ?? null,
            'platform' => $data['platform'] ?? null,
            'version'  => $data['app_version'] ?? null,
            'status'   => $data['status'] ?? null,
            'endpoint' => $data['endpoint'] ?? null,
        ]);

        // Append to rolling JSON log
        $file   = storage_path('logs/frontend-errors.json');
        $errors = [];
        if (file_exists($file)) {
            $raw    = @file_get_contents($file);
            $errors = $raw ? (json_decode($raw, true) ?: []) : [];
        }

        array_unshift($errors, array_merge($data, [
            'ts'      => now()->toISOString(),
            'user_id' => auth()->id(),
        ]));
        $errors = array_slice($errors, 0, 200); // keep last 200

        @file_put_contents($file, json_encode($errors, JSON_PRETTY_PRINT));

        return response()->json(['ok' => true]);
    }

    /**
     * Meldingen die geen fout beschrijven maar normaal gedrag.
     */
    private function isRuis(array $data): bool
    {
        $type    = $data['type'] ?? '';
        $message = $data['message'] ?? '';
        $status  = $data['status'] ?? null;
        $endpoint = $data['endpoint'] ?? '';

        // Diagnostiek van de iOS-widgetbrug: "overgeslagen" (web/Android heeft
        // geen widgets) en een geslaagde setAccount zijn juist het goede pad.
        // Een MISLUKT/gooide-regel blijft wél staan.
        if ($type === 'widget_diag'
            && preg_match('/overgeslagen|setAccount ok|ok voor \d/i', $message)) {
            return true;
        }

        // De gebruiker gaf geen locatietoestemming. Een keuze, geen defect;
        // de app legt zelf uit wat er dan niet werkt. Een geklapte
        // permissie-aanroep ("gooide een fout") blijft wel gemeld.
        if ($type === 'location_diag' && str_contains($message, 'permissie geweigerd')) {
            return true;
        }

        // Een locatie-aanvraag die binnen zijn tijd geen fix kreeg (binnenshuis,
        // net uit de achtergrond, vliegmodus). De app vangt dat zelf op en de
        // gewone watch pikt de positie later op: een omstandigheid, geen defect.
        // Nieuwe builds melden dit niet meer; oude sturen het bij elke terugkeer
        // naar de kaart, dus het filter hoort ook hier.
        if ($type === 'location_diag'
            && preg_match('/getCurrentPosition mislukt.{1,5}code=(timeout|3|OS-PLUG-GLOC-0010)(\s|$)/u', $message)) {
            return true;
        }

        // Locatiestroom die zichzelf herstelde, of een toestel waarop de
        // gebruiker Locatievoorzieningen uit zette / de toestemming weigerde.
        // "Hersteld" is het goede pad van de herstart-logica (de eerste positie
        // komt pas als het toestel weer een fix heeft, soms uren later in de
        // achtergrond) en OS-PLUG-GLOC-0002 is een tijdelijke "locatie even
        // onbekend" van iOS waar de app zelf een herstart op plant. Een herstart
        // die géén positie oplevert blijft zichtbaar via andere meldingen.
        if ($type === 'location_diag') {
            if (preg_match('/locatiestream hersteld na/u', $message)) {
                return true;
            }
            if (preg_match('/location services are not enabled|location permission request was denied|code=OS-PLUG-GLOC-0003/i', $message)) {
                return true;
            }
            if (preg_match('/getCurrentPosition mislukt.{1,5}code=OS-PLUG-GLOC-0002(\s|$)/u', $message)) {
                return true;
            }
        }

        // Horloge: een afgebroken verzoek (URLError.cancelled) ontstaat als het
        // scherm verdwijnt terwijl het laadt — geen netwerkprobleem.
        if ($type === 'watch_diag' && preg_match('/mislukt\s.{1,3}\scancell?ed$/iu', $message)) {
            return true;
        }

        // Prestatiemeting van de navigatie: alleen een venster dat echt slecht
        // liep is een bevinding. Dezelfde drempels als perfDiagnostics.js
        // (isBevinding); oudere builds sturen élk venster, ook de goede.
        if ($type === 'perf_diag' && $this->isGoedePerformance($message)) {
            return true;
        }

        // Scripts die niet van ons zijn: in-app-browsers, wallet-extensies en
        // dergelijke injecteren code in de pagina (stackframes zonder bestand of
        // met de pagina zelf als bron, bv. `window.ethereum.selectedAddress`).
        // Een fout van onze eigen bundel heeft altijd een .js-bestand in de stack.
        if (in_array($type, ['js_error', 'unhandled_rejection'], true)
            && $this->isGeinjecteerdScript($data['stack'] ?? '')) {
            return true;
        }

        if ($type === 'api_error') {
            // Kaart/missie bestaat niet (meer) op de server: verwijderd door
            // jezelf of een ander toestel, of een verouderde verwijzing. De app
            // vangt dit op en valt terug op lokale data.
            if ((int) $status === 404
                && strtoupper($data['method'] ?? '') === 'GET'
                && preg_match('/No query results for model \[App\\\\+Models\\\\+(Mission|Map)\]/', ($data['response'] ?? '') . ' ' . $message)) {
                return true;
            }

            // Snelheidsbegrenzer: de gebruiker ziet "probeer het zo nog eens".
            if ((int) $status === 429) {
                return true;
            }

            // Niet (meer) ingelogd — de app stuurt zelf naar het inlogscherm.
            if ((int) $status === 401) {
                return true;
            }

            // E-mailadres nog niet bevestigd: de verificatiemuur doet precies
            // wat hij moet. Oude builds (vóór het front-endfilter) vuren op
            // /verify-email nog chat/routemaps-calls af die hier 403 op krijgen.
            if (str_contains(($data['response'] ?? '') . ' ' . $message, '"code":"email_verification_required"')) {
                return true;
            }

            // Een fout wachtwoord of een verlopen code bij inloggen, e-mail
            // bevestigen of het opheffen van je account is normaal gebruik.
            if ((int) $status === 422
                && preg_match('#/(login|register|password|email/verif|account/deletion)#', $endpoint . ' ' . $message)) {
                return true;
            }

            // Server even niet beschikbaar: onderhoudsmodus tijdens een deploy
            // (Laravel's "Service Unavailable") of een webserver zonder vrije
            // PHP-worker (HTML-foutpagina). Een omstandigheid, geen defect.
            // Nieuwe builds proberen het zelf opnieuw en melden dit niet meer;
            // oude builds sturen het nog, dus het filter hoort ook hier.
            if ((int) $status === 503
                && preg_match('/Service Unavailable|<!DOCTYPE|<html/i', ($data['response'] ?? '') . ' ' . $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "nav perf #3: 56fps, 34 jank (0 zwaar), …" — true als het venster goed
     * liep. Oudere builds sturen geen "(N zwaar)"; dan telt alleen fps + jank.
     * Een bericht dat we niet kunnen lezen wordt nooit weggefilterd.
     */
    private function isGoedePerformance(string $message): bool
    {
        if (! preg_match('/nav perf #\d+: (\d+)fps, (\d+) jank(?: \((\d+) zwaar\))?/', $message, $m)) {
            return false;
        }

        $fps    = (int) $m[1];
        $jank   = (int) $m[2];
        $zwaar  = (int) ($m[3] ?? 0);
        $frames = max(1, $fps * 15); // meetvenster is ~15s

        if ($zwaar >= 3) {
            return false;
        }

        // Gedrosseld op ~30fps (spaarstand/thermisch): geen bevinding, en de
        // jank-teller van oudere builds telt dan elk frame mee.
        if ($fps >= 25 && $fps <= 34) {
            return true;
        }

        if ($fps < 45) {
            return false;
        }

        return $jank / $frames <= 0.1;
    }

    /**
     * Stack zonder enig .js-bestand = code die niet uit onze bundel komt
     * (geëvalueerd/geïnjecteerd door een webview of extensie). Zonder stack
     * valt er niets te zeggen, dus dan niet filteren.
     */
    private function isGeinjecteerdScript(string $stack): bool
    {
        $stack = trim($stack);

        return $stack !== '' && ! preg_match('/\.m?js\b/i', $stack);
    }
}
