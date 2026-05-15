<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use App\Mail\ResetPasswordMail;
use Illuminate\Support\Facades\Mail;
use App\Mail\PasswordResetSuccess;

class PasswordResetController extends Controller
{
    // Send password reset link
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:users,email']);
    
        $status = Password::sendResetLink($request->only('email'), function ($user, $token) {
            $frontendUrl = config('auth.passwords.users.url');
            $resetUrl = $frontendUrl . '?token=' . $token . '&email=' . urlencode($user->email);
    
            // Send the custom reset password email
            Mail::to($user->email)->send(new ResetPasswordMail($resetUrl));
        });
    
        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'Password reset link sent.'], 200);
        }
    
        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }
    

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email|exists:users,email',
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
