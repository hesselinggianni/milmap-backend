<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class FeatureRequestController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_name'   => 'required|string|max:255',
                'user_email'  => 'required|email|max:255',
                'title'       => 'required|string|max:255',
                'description' => 'required|string|min:10|max:5000',
                'priority'    => 'nullable|in:low,medium,high',
                'category'    => 'nullable|string|max:100',
            ]);

            $validated['priority']  = $validated['priority'] ?? 'medium';
            $validated['timestamp'] = now()->setTimezone('Europe/Amsterdam')->format('d-m-Y \o\m H:i:s');

            $this->sendEmail($validated);

            return response()->json([
                'message' => 'Feature request received successfully',
                'data' => [
                    'user_name'  => $validated['user_name'],
                    'user_email' => $validated['user_email'],
                    'title'      => $validated['title'],
                    'timestamp'  => $validated['timestamp'],
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error processing feature request',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    private function sendEmail(array $data)
    {
        $adminEmail = 'gianni@onavan.com';

        Mail::send('emails.feature-request', ['data' => $data], function ($message) use ($adminEmail, $data) {
            $message->to($adminEmail)
                ->subject('Feature Request: ' . $data['title'])
                ->replyTo($data['user_email'], $data['user_name']);
        });
    }
}
