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
use Illuminate\Support\Facades\RateLimiter;
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
                'message' => 'If this email address is known to us, you will receive a login code.',
            ], 200);
        }

        // Alleen lokale development vanaf loopback mag de uurlimiet overslaan.
        $localDevelopment = app()->environment('local')
            && in_array($request->ip(), ['127.0.0.1', '::1'], true);

        // Rate limiting: max 5 codes per e-mailadres per uur, plus een ruime
        // IP-limiet (30/uur) tegen misbruik zonder gedeelde netwerken te blokkeren.
        $since = Carbon::now()->subHour();
        $recentForEmail = UserLoginCode::where('email', $validated['email'])
            ->where('created_at', '>', $since)
            ->count();
        $recentForIp = UserLoginCode::where('ip_address', $request->ip())
            ->where('created_at', '>', $since)
            ->count();

        if (!$localDevelopment && ($recentForEmail >= 5 || $recentForIp >= 30)) {
            return response()->json([
                'message' => 'Too many requests. Please try again later.',
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
            // Mail::to($user) i.p.v. het kale adres: zo pakt Laravel
            // User::preferredLocale() op — Engels, tenzij het account
            // expliciet op Nederlands staat.
            Mail::to($user)->send(new UserLoginCodeMail($code));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[login-code] kon inlogcode niet versturen: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'If this email address is known to us, you will receive a login code.',
        ], 200);
    }

    public function verifyCode(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
        ]);

        // Per account, not per IP: the route throttle counts per installation
        // (thousands of users share a school/barracks NAT), so a 6-digit code
        // needs its own cap — 10 wrong guesses per address per 15 minutes.
        $attemptKey = 'login-code-verify:' . sha1(mb_strtolower(trim($validated['email'])));
        if (RateLimiter::tooManyAttempts($attemptKey, 10)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Request a new code in a few minutes.',
            ]);
        }

        $user = User::where('email', $validated['email'])->first();

        $loginCode = UserLoginCode::where('email', $validated['email'])
            ->where('code', $validated['code'])
            ->unexpired()
            ->unused()
            ->first();

        if (!$user || $user->isArchived() || !$loginCode) {
            RateLimiter::hit($attemptKey, 900);
            throw ValidationException::withMessages([
                'code' => 'Invalid or expired code.',
            ]);
        }

        RateLimiter::clear($attemptKey);
        $loginCode->markAsUsed();

        return $this->finalizeLogin($request, $user);
    }
}
