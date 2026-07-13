<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class BugReportController extends Controller
{
    /**
     * Store a new bug report and send email
     */
    public function store(Request $request)
    {
        try {
            // Naam/e-mail zijn optioneel vanuit de client: dit endpoint is
            // auth:sanctum, dus we vullen ze zo nodig aan met het ingelogde
            // account. `url` niet als strikte URL valideren — de native app
            // (Capacitor) stuurt schema's als capacitor://localhost/... die
            // Laravels `url`-regel afkeurt en zo een 422 veroorzaakten.
            $validated = $request->validate([
                'user_name'  => 'nullable|string|max:255',
                'user_email' => 'nullable|email|max:255',
                'message'    => 'required|string|min:1',
                'url'        => 'nullable|string|max:2000',
                'user_agent' => 'nullable|string|max:1000',
            ]);

            $user = $request->user();
            $fallbackName = $user
                ? (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email)
                : null;

            $data = [
                'user_name'  => ($validated['user_name'] ?? null) ?: ($fallbackName ?: 'Onbekende gebruiker'),
                'user_email' => ($validated['user_email'] ?? null) ?: ($user->email ?? null),
                'message'    => $validated['message'],
                'url'        => $validated['url'] ?? null,
                'user_agent' => $validated['user_agent'] ?? null,
            ];

            // Send email to the admin
            $this->sendBugReportEmail($data);

            return response()->json([
                'message' => 'Bug report received successfully',
                'data' => [
                    'user_name'  => $data['user_name'],
                    'user_email' => $data['user_email'],
                    'timestamp'  => now(),
                ]
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error processing bug report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send bug report email to admin
     */
    private function sendBugReportEmail(array $data)
    {
        $adminEmail = config('mail.admin_mail', config('mail.from.address'));
        $data['timestamp'] = now()->setTimezone('Europe/Amsterdam')->format('d-m-Y \o\m H:i:s');

        Mail::send('emails.bug-report', ['data' => $data], function ($message) use ($adminEmail, $data) {
            $message->to($adminEmail)
                ->subject('Bug Report: ' . \Illuminate\Support\Str::limit($data['message'], 50));

            // Alleen een reply-to zetten als we een geldig e-mailadres hebben.
            if (! empty($data['user_email'])) {
                $message->replyTo($data['user_email'], $data['user_name']);
            }
        });
    }
}
