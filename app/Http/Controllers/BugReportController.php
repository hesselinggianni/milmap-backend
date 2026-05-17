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
            $validated = $request->validate([
                'user_name' => 'required|string|max:255',
                'user_email' => 'required|email|max:255',
                'message' => 'required|string|min:1',
                'url' => 'nullable|string|url',
                'user_agent' => 'nullable|string',
            ]);

            // Send email to the admin
            $this->sendBugReportEmail($validated);

            return response()->json([
                'message' => 'Bug report received successfully',
                'data' => [
                    'user_name' => $validated['user_name'],
                    'user_email' => $validated['user_email'],
                    'timestamp' => now(),
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
        $adminEmail = env('ADMIN_MAIL', config('mail.from.address'));
        $data['timestamp'] = now()->setTimezone('Europe/Amsterdam')->format('d-m-Y \o\m H:i:s');

        Mail::send('emails.bug-report', ['data' => $data], function ($message) use ($adminEmail, $data) {
            $message->to($adminEmail)
                ->subject('Bug Report: ' . \Illuminate\Support\Str::limit($data['message'], 50))
                ->replyTo($data['user_email'], $data['user_name']);
        });
    }
}
