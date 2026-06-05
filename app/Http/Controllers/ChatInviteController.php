<?php

namespace App\Http\Controllers;

use App\Mail\ChatInviteMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ChatInviteController extends Controller
{
    /**
     * Build an invite URL for the given email and e-mail it to the invitee.
     * The URL is also returned so the inviter can copy/share it manually as a
     * fallback. A mail failure never fails the request (the link still works).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $inviter     = Auth::user();
        $inviterName = $inviter->full_name ?? $inviter->first_name ?? 'Een MilMap-gebruiker';
        $base        = rtrim(config('app.frontend_url', 'https://app.milmap.nl'), '/');
        $url         = $base . '/register?ref=chat&inviter=' . urlencode($inviterName)
                     . '&email=' . urlencode($data['email']);

        $emailed = false;
        try {
            Mail::to($data['email'])->send(new ChatInviteMail($inviterName, $url));
            $emailed = true;
        } catch (\Throwable $e) {
            // SMTP unreachable — the inviter can still copy/share the link.
            Log::warning('[chat] invite email failed: ' . $e->getMessage());
        }

        return response()->json([
            'url'     => $url,
            'emailed' => $emailed,
        ]);
    }
}
