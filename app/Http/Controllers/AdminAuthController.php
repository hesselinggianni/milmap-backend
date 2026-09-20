<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AdminLoginCode;
use App\Mail\AdminLoginCodeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class AdminAuthController extends Controller
{
    public function requestCode(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $email = mb_strtolower(trim($data['email']));
        $key = 'admin-code-request:'.hash('sha256', $email);
        // Per-account limit complements the route's per-IP limit, including
        // unknown accounts so response status cannot reveal administrator status.
        if (RateLimiter::hit($key, 3600) > 5) {
            return response()->json(['message' => 'Te veel aanvragen. Probeer later opnieuw.'], 429);
        }

        $user = User::where('email', $email)->where('is_admin', true)->first();
        if ($user) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            DB::transaction(function () use ($user, $email, $code, $request) {
                // Serialize issuances and invalidate all older challenges.
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                AdminLoginCode::where('email', $email)->unused()->update(['used_at' => now()]);
                AdminLoginCode::create([
                    'email' => $email,
                    'code' => Hash::driver('bcrypt')->make($code),
                    'ip_address' => $request->ip(),
                    'expires_at' => now()->addMinutes(15),
                ]);
            });
            try {
                $mail = (new AdminLoginCodeMail($code))->withSymfonyMessage(function ($message) {
                    $message->getHeaders()->addTextHeader('X-Milmap-Sensitive', '1');
                });
                Mail::to($user)->send($mail);
            } catch (\Throwable $e) {
                // Do not return internal transport errors or account existence.
                logger()->warning('Admin login email delivery failed', ['user_id' => $user->id]);
            }
        }
        return response()->json(['message' => 'Als dit account admin-toegang heeft, ontvang je een code.', 'email' => $email]);
    }

    public function verifyCode(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'code' => 'required|digits:6']);
        $email = mb_strtolower(trim($data['email']));
        $key = 'admin-code-verify:'.hash('sha256', $email);
        if (RateLimiter::hit($key, 900) > 5) {
            return response()->json(['message' => 'Te veel pogingen. Probeer later opnieuw.'], 429);
        }

        $result = DB::transaction(function () use ($email, $data) {
            $user = User::where('email', $email)->where('is_admin', true)->lockForUpdate()->first();
            if (!$user) return null;
            $challenge = AdminLoginCode::where('email', $email)->unused()->unexpired()
                ->latest('id')->lockForUpdate()->first();
            // Legacy plaintext challenges intentionally expire at this rollout.
            if (!$challenge || !str_starts_with($challenge->code, '$2y$') ||
                !Hash::driver('bcrypt')->check((string) $data['code'], $challenge->code)) return null;
            $challenge->markAsUsed();
            $token = $user->createToken('Admin Token', ['admin', 'user'], now()->addHours(8))->plainTextToken;
            return ['message' => 'Ingelogd als admin', 'token' => $token, 'user' => [
                'id' => $user->id, 'email' => $user->email,
                'name' => $user->name ?? trim($user->first_name.' '.$user->last_name),
                'is_admin' => true,
            ]];
        });
        if (!$result) {
            return response()->json(['message' => 'Ongeldige of verlopen code', 'error' => 'invalid_code'], 401);
        }
        RateLimiter::clear($key);
        return response()->json($result);
    }
}
