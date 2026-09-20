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

        if ($type === 'api_error') {
            // Snelheidsbegrenzer: de gebruiker ziet "probeer het zo nog eens".
            if ((int) $status === 429) {
                return true;
            }

            // Een fout wachtwoord of een verlopen code bij inloggen, e-mail
            // bevestigen of het opheffen van je account is normaal gebruik.
            if ((int) $status === 422
                && preg_match('#/(login|register|password|email/verif|account/deletion)#', $endpoint . ' ' . $message)) {
                return true;
            }
        }

        return false;
    }
}
