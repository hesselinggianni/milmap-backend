<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AdminLoginCode;
use App\Mail\AdminLoginCodeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AdminAuthController extends Controller
{
    /**
     * Request an admin login code
     */
    public function requestCode(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
            ]);

            // Check if user exists
            $user = User::where('email', $validated['email'])->first();

            if (!$user) {
                return response()->json([
                    'message' => 'Dit emailadres is niet gevonden.',
                    'error' => 'not_found'
                ], 404);
            }

            // Check if user is admin
            if (!$user->is_admin) {
                return response()->json([
                    'message' => 'Dit account heeft geen admin-toegang.',
                    'error' => 'not_admin'
                ], 403);
            }

            // Rate limiting: 5 codes per IP per hour
            $recentCodes = AdminLoginCode::where('ip_address', $request->ip())
                ->where('created_at', '>', Carbon::now()->subHour())
                ->count();

            if ($recentCodes >= 5) {
                return response()->json([
                    'message' => 'Te veel aanvragen. Probeer het later opnieuw.',
                    'error' => 'rate_limit'
                ], 429);
            }

            // Generate 6-digit code
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Create admin login code (expires in 15 minutes)
            AdminLoginCode::create([
                'email' => $validated['email'],
                'code' => $code,
                'ip_address' => $request->ip(),
                'expires_at' => Carbon::now()->addMinutes(15),
            ]);

            // Send code via email
            Mail::to($validated['email'])->send(new AdminLoginCodeMail($code));

            return response()->json([
                'message' => 'Code verzonden naar ' . $validated['email'],
                'email' => $validated['email'],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validatiefout',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Fout bij verzending',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify admin login code and return token
     */
    public function verifyCode(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email',
                'code' => 'required|string|size:6',
            ]);

            // Check if user is admin
            $user = User::where('email', $validated['email'])
                ->where('is_admin', true)
                ->first();

            if (!$user) {
                return response()->json([
                    'message' => 'Deze email is geen admin',
                    'error' => 'not_admin'
                ], 403);
            }

            // Find unexpired, unused code
            $loginCode = AdminLoginCode::where('email', $validated['email'])
                ->where('code', $validated['code'])
                ->unexpired()
                ->unused()
                ->first();

            if (!$loginCode) {
                return response()->json([
                    'message' => 'Ongeldige of verlopen code',
                    'error' => 'invalid_code'
                ], 401);
            }

            // Mark code as used
            $loginCode->markAsUsed();

            // Create Sanctum token for admin
            $token = $user->createToken('Admin Token')->plainTextToken;

            return response()->json([
                'message' => 'Ingelogd als admin',
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name ?? $user->first_name . ' ' . $user->last_name,
                    'is_admin' => $user->is_admin,
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validatiefout',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Fout bij verificatie',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
