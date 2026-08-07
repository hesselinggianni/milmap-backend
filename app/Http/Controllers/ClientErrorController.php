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
}
