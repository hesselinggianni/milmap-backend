<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * GET /api/v1/email/verify/{id}/{hash}
     *
     * Doelpunt van de link in de verificatiemail. Publiek bereikbaar — de
     * geldigheid komt van de signed URL (signature + 7-daagse verloop). Bij
     * succes/fout sturen we netjes terug naar de webapp met een ?verified-vlag.
     */
    public function verify(Request $request, int $id, string $hash)
    {
        $appUrl = rtrim(config('app.app_url', 'https://app.milmap.nl'), '/');

        if (! $request->hasValidSignature()) {
            return redirect()->away($appUrl . '/login?verified=expired');
        }

        $user = User::find($id);

        if (! $user || $user->isArchived()
            || ! hash_equals($user->emailVerificationHash(), (string) $hash)) {
            return redirect()->away($appUrl . '/login?verified=invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->away($appUrl . '/login?verified=already');
        }

        $user->markEmailVerified();

        return redirect()->away($appUrl . '/login?verified=1');
    }

    /**
     * POST /api/v1/email/verification-notification
     *
     * "Opnieuw versturen" vanaf de wall. Vereist een ingelogde gebruiker; staat
     * bewust BUITEN de e-mailverificatie-middleware zodat een gewallde gebruiker
     * 'm kan aanroepen.
     */
    public function resend(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if ($user->isArchived()) {
            return response()->json([
                'message' => 'Dit account is gearchiveerd. Registreer opnieuw.',
                'code'    => 'account_archived',
            ], 403);
        }
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message'      => 'Je e-mailadres is al bevestigd.',
                'verification' => $user->verificationState(),
            ], 200);
        }

        EmailVerificationService::send($user);

        return response()->json([
            'message'      => 'Verificatiemail verstuurd. Controleer je inbox (en spam).',
            'verification' => $user->verificationState(),
        ], 200);
    }
}
