<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use App\Models\User;
use App\Mail\ResetPasswordMail;
use Illuminate\Support\Facades\Mail;
use App\Mail\PasswordResetSuccess;

class PasswordResetController extends Controller
{
    // Send password reset link
    public function sendResetLink(Request $request)
    {
        // No `exists:users,email` rule and no status-dependent response: an
        // unknown address must look identical to a known one, otherwise this
        // endpoint becomes an account-enumeration oracle. We attempt the send
        // (which silently no-ops for unknown / throttled addresses) and always
        // return the same generic 200.
        $request->validate(['email' => 'required|email']);

        Password::sendResetLink($request->only('email'), function ($user, $token) {
            $frontendUrl = config('auth.passwords.users.url');
            $resetUrl = $frontendUrl . '?token=' . $token . '&email=' . urlencode($user->email);

            // Send the custom reset password email
            Mail::to($user->email)->send(new ResetPasswordMail($resetUrl));
        });

        return response()->json([
            'message' => 'Als dit e-mailadres bij ons bekend is, ontvang je een link om je wachtwoord opnieuw in te stellen.',
        ], 200);
    }
    

    public function resetPassword(Request $request)
    {
        // Drop `exists:users,email` here too — the reset is already token-scoped,
        // and the generic 400 below covers an unknown address without leaking
        // whether the account exists.
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);
    
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                Mail::to($user->email)->send(new PasswordResetSuccess($user));
            }
        );
    
        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset successful. You can now log in.'], 200);
        }
    
        return response()->json(['message' => 'Invalid token or email.'], 400);
    }
    
}
