<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Auth\Concerns\IssuesLoginSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    use IssuesLoginSession;

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Two-dimensional throttle. A pure per-IP limit has two failure modes:
        //   • shared-NAT lockout — one bad actor behind a carrier-grade NAT /
        //     office gateway locks out every other legitimate user on that IP;
        //   • distributed bypass — an attacker rotating IPs brute-forces a
        //     single account without ever tripping the per-IP counter.
        // So we keep a strict per-ACCOUNT counter (catches IP rotation against
        // one email) plus a looser per-IP counter (catches one host spraying
        // many accounts) and block when EITHER trips. The per-account limit is
        // the tight one, so honest users sharing a NAT aren't punished for a
        // neighbour's failures.
        $emailKey = 'login-attempts:email:' . sha1(mb_strtolower($credentials['email']));
        $ipKey    = 'login-attempts:ip:' . $request->ip();

        if (RateLimiter::tooManyAttempts($emailKey, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in a few minutes.',
            ]);
        }

        $user = User::where('email', $credentials['email'])->first();

        // Passwordless account (nog geen wachtwoord ingesteld): Hash::check()
        // gooit een fout op een lege hash, dus behandel dit expliciet als
        // "onjuiste inloggegevens" — de gebruiker moet een inlogcode aanvragen.
        if (!$user || !$user->password || !Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($emailKey, 900); // 15-min decay
            RateLimiter::hit($ipKey, 900);
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        RateLimiter::clear($emailKey);
        RateLimiter::clear($ipKey);

        return $this->finalizeLogin($request, $user);
    }

    /**
     * E-mail-eerst auth-flow: geeft terug of een e-mailadres al een (niet-
     * gearchiveerd) account heeft. De client kiest op basis hiervan tussen het
     * inlog- of registratiescherm. Bewust minimaal (alleen een boolean) en
     * zwaar gethrottled (zie route) om account-enumeratie te ontmoedigen.
     */
    public function checkEmail(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $user = User::where('email', $data['email'])->first();
        // Een gearchiveerd account moet opnieuw registreren, dus behandelen we
        // het als "bestaat niet" → de client toont het registratiescherm.
        $exists = $user && !$user->isArchived();

        // Vertelt de client of het wachtwoordveld getoond moet worden, of dat
        // dit een passwordless account is dat alleen met een inlogcode kan.
        return response()->json([
            'exists' => (bool) $exists,
            'has_password' => (bool) ($exists && $user->password),
        ]);
    }

}
