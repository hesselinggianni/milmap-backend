<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\IssuesLoginSession;
use App\Http\Controllers\Controller;
use App\Mail\UserLoginCodeMail;
use App\Models\User;
use App\Models\UserLoginCode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Passwordless login: een 6-cijferige code per mail, zelfde opzet als de
 * admin-inlogcode (AdminAuthController). Werkt voor elk account — met of
 * zonder wachtwoord — zodat een vergeten wachtwoord ook via deze weg is
 * op te lossen zonder eerst naar de reset-pagina te moeten.
 */
class LoginCodeController extends Controller
{
    use IssuesLoginSession;

    public function requestCode(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Geen account-enumeratie: altijd dezelfde 200, of het adres nu
        // bestaat of niet (zelfde aanpak als password/reset-link).
        if (!$user || $user->isArchived()) {
            return response()->json([
                'message' => 'Als dit e-mailadres bij ons bekend is, ontvang je een inlogcode.',
            ], 200);
        }

        // Rate limiting: max 5 codes per IP per uur (zelfde limiet als admin).
        $recentCodes = UserLoginCode::where('ip_address', $request->ip())
            ->where('created_at', '>', Carbon::now()->subHour())
            ->count();

        if ($recentCodes >= 5) {
            return response()->json([
                'message' => 'Te veel aanvragen. Probeer het later opnieuw.',
                'error' => 'rate_limit',
            ], 429);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        UserLoginCode::create([
            'email' => $validated['email'],
            'code' => $code,
            'ip_address' => $request->ip(),
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        try {
            Mail::to($validated['email'])->send(new UserLoginCodeMail($code));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[login-code] kon inlogcode niet versturen: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Als dit e-mailadres bij ons bekend is, ontvang je een inlogcode.',
        ], 200);
    }

    public function verifyCode(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        $user = User::where('email', $validated['email'])->first();

        $loginCode = UserLoginCode::where('email', $validated['email'])
            ->where('code', $validated['code'])
            ->unexpired()
            ->unused()
            ->first();

        if (!$user || $user->isArchived() || !$loginCode) {
            throw ValidationException::withMessages([
                'code' => 'Ongeldige of verlopen code.',
            ]);
        }

        $loginCode->markAsUsed();

        return $this->finalizeLogin($request, $user);
    }
}
