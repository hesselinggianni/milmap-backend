<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\LoginNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $key = 'login-attempts:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in a few minutes.',
            ]);
        }

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        RateLimiter::clear($key);

        $token = $user->createToken('API Token')->plainTextToken;

        $this->sendLoginNotification($request, $user);

        return response()->json([
            'message' => 'Logged in successfully.',
            'user' => $user,
            'token' => $token,
        ], 200);
    }

    private function sendLoginNotification(Request $request, User $user): void
    {
        $ip = $request->ip();
        $location = $this->resolveLocation($ip);
        $device = $this->parseDevice($request->userAgent() ?? 'Onbekend');
        $loginTime = now()->setTimezone('Europe/Amsterdam')->format('d-m-Y \o\m H:i:s');

        Mail::to($user->email)->send(
            new LoginNotification($user->name ?? $user->email, $ip, $location, $device, $loginTime)
        );
    }

    private function resolveLocation(string $ip): string
    {
        // Lokale / private IP's kunnen niet worden opgezocht
        if (in_array($ip, ['127.0.0.1', '::1']) || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return 'Lokaal netwerk';
        }

        try {
            $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}?fields=status,city,regionName,country&lang=nl");

            if ($response->ok()) {
                $data = $response->json();
                if (($data['status'] ?? '') === 'success') {
                    $parts = array_filter([$data['city'] ?? null, $data['regionName'] ?? null, $data['country'] ?? null]);
                    return implode(', ', $parts) ?: 'Onbekend';
                }
            }
        } catch (\Throwable) {
            // Geen locatie beschikbaar — stil falen
        }

        return 'Onbekend';
    }

    private function parseDevice(string $userAgent): string
    {
        $browser = 'Onbekende browser';
        $os = 'Onbekend OS';

        // Browser detectie
        if (str_contains($userAgent, 'Edg/')) $browser = 'Microsoft Edge';
        elseif (str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera')) $browser = 'Opera';
        elseif (str_contains($userAgent, 'Chrome')) $browser = 'Chrome';
        elseif (str_contains($userAgent, 'Firefox')) $browser = 'Firefox';
        elseif (str_contains($userAgent, 'Safari')) $browser = 'Safari';

        // OS detectie
        if (str_contains($userAgent, 'Windows NT')) $os = 'Windows';
        elseif (str_contains($userAgent, 'Mac OS X')) $os = 'macOS';
        elseif (str_contains($userAgent, 'Android')) $os = 'Android';
        elseif (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) $os = 'iOS';
        elseif (str_contains($userAgent, 'Linux')) $os = 'Linux';

        return "{$browser} op {$os}";
    }
}
