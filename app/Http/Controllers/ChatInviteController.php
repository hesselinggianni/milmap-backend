<?php

namespace App\Http\Controllers;

use App\Mail\ChatInviteMail;
use App\Models\ChatInvite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

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
        $inviterName = $inviter->full_name ?? $inviter->first_name ?? 'Een Milmap-gebruiker';
        $email       = mb_strtolower(trim($data['email']));
        $base        = rtrim(config('app.frontend_url', 'https://app.milmap.nl'), '/');
        $url         = $base . '/register?ref=chat&inviter=' . urlencode($inviterName)
                     . '&email=' . urlencode($email);

        // Persist the invite so we can report acceptance on the inviter's Hub
        // activity timeline once this person registers. If the e-mail already
        // belongs to an account there's nothing to "accept", so skip tracking.
        if (! User::where('email', $email)->exists()) {
            try {
                ChatInvite::updateOrCreate(
                    ['inviter_id' => $inviter->id, 'email' => $email],
                    ['status' => 'pending']
                );
            } catch (\Throwable $e) {
                Log::warning('[chat] invite persist failed: ' . $e->getMessage());
            }
        }

        // Anti-spam: stuur de uitnodigingsmail hoogstens 1× per uur naar
        // hetzelfde adres. Een mislukte verzending verbruikt de limiet niet,
        // zodat een herkansing mogelijk blijft. Bewust op de ONTVANGER gescoped
        // (productvereiste: "die persoon kan max. 1 mail per uur ontvangen") en
        // met een eigen prefix, los van de chat-request-mail, zodat de twee
        // kanalen elkaar nooit kunnen onderdrukken.
        $throttleKey = 'chat-invite:' . sha1($email);
        $throttled   = RateLimiter::tooManyAttempts($throttleKey, 1);

        $emailed = false;
        if (! $throttled) {
            try {
                Mail::to($data['email'])->send(new ChatInviteMail($inviterName, $url));
                $emailed = true;
                RateLimiter::hit($throttleKey, 3600); // 1 uur
            } catch (\Throwable $e) {
                // SMTP unreachable — the inviter can still copy/share the link.
                Log::warning('[chat] invite email failed: ' . $e->getMessage());
            }
        }

        return response()->json([
            'url'       => $url,
            'emailed'   => $emailed,
            'throttled' => $throttled,
        ]);
    }
}
