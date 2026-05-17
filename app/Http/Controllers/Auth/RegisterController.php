<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewUserRegistered;

class RegisterController extends Controller
{
    public function store(Request $request)
    {

           // Check if the user already exists
           $existingUser = User::where('email', $request->email)->first();

           if ($existingUser) {
               throw ValidationException::withMessages([
                   'email' => ['Deze gebruiker is al geregistreerd.'],
               ]);
           }

           
        // Validate input data
        $validator = Validator::make($request->all(), [       
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        // Create the user
        $user = User::create([      
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);
        
        Mail::to('hesselinggianni@gmail.com')
        ->send(new NewUserRegistered($user));

        // Generate a Sanctum token
        $token = $user->createToken('API Token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully.',
            'user' => $user,
            'token' => $token,
        ], 201);
    }
}
