<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatKeyController extends Controller
{
    /**
     * Publish the authenticated user's X25519 public key (base64).
     * The matching private key never leaves the user's browser.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'public_key' => ['required', 'string', 'max:255'],
        ]);

        $user = Auth::user();
        $user->public_key = $data['public_key'];
        $user->save();

        return response()->json([
            'public_key' => $user->public_key,
        ]);
    }

    /**
     * Return the authenticated user's own key status (whether one is set).
     */
    public function me()
    {
        return response()->json([
            'public_key' => Auth::user()->public_key,
        ]);
    }

    /**
     * Fetch another user's public key so the client can seal a message to them.
     */
    public function show($id)
    {
        $user = User::select('id', 'public_key')->findOrFail($id);

        return response()->json([
            'id'         => $user->id,
            'public_key' => $user->public_key,
        ]);
    }
}
