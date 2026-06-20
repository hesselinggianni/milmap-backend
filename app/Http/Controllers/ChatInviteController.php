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

        // ── Globale dagelijkse limiet per uitnodiger (25/dag) ───────────
        // Voorkomt spam-campagnes. De limiet zit op de UITNODIGER, niet op de
        // ontvanger, zodat één account niet tientallen mensen per dag kan
        // bestoken. Venster: 24 uur (86400s). Retourneert een eigen vlag zodat
        // de frontend een passende melding kan tonen.
        $dailyKey         = 'chat-invite-daily:' . $inviter->id;
        $dailyLimitReached = RateLimiter::tooManyAttempts($dailyKey, 25);

        // ── Per-ontvanger uurlimiet ──────────────────────────────────────
        // Bewust op de ONTVANGER gescoped: dezelfde persoon kan max. 1× per
        // uur een uitnodigingsmail ontvangen, ongeacht hoeveel verschillende
        // gebruikers hem uitnodigen. Eigen prefix, los van chat-request-mail.
        $throttleKey = 'chat-invite:' . sha1($email);
        $throttled   = RateLimiter::tooManyAttempts($throttleKey, 1);

        $emailed = false;
        if (! $throttled && ! $dailyLimitReached) {
            try {
                Mail::to($data['email'])->send(new ChatInviteMail($inviterName, $url));
                $emailed = true;
                RateLimiter::hit($throttleKey, 3600);  // 1 uur (per ontvanger)
                RateLimiter::hit($dailyKey,    86400); // 24 uur (per uitnodiger)
            } catch (\Throwable $e) {
                // SMTP unreachable — the inviter can still copy/share the link.
                Log::warning('[chat] invite email failed: ' . $e->getMessage());
            }
        }

        return response()->json([
            'url'               => $url,
            'emailed'           => $emailed,
            'throttled'         => $throttled,
            'daily_limit_reached' => $dailyLimitReached,
        ]);
    }
}
