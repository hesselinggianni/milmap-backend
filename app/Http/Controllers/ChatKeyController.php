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

    /**
     * Store the zero-knowledge key escrow: the user's PRIVATE key, wrapped
     * client-side with a key derived from their pincode (Argon2id). The server
     * stores only ciphertext + KDF parameters and can decrypt nothing.
     *
     * Setting the escrow also promotes the wrapped keypair to the account's
     * public key, so the SAME keypair is reachable + readable on every device.
     */
    public function storeEscrow(Request $request)
    {
        $data = $request->validate([
            'public_key' => ['required', 'string', 'max:255'],
            'escrow'     => ['required', 'string', 'max:20000'],
            'salt'       => ['required', 'string', 'max:255'],
            'nonce'      => ['required', 'string', 'max:255'],
            'ops'        => ['required', 'integer', 'min:1', 'max:100'],
            'mem'        => ['required', 'integer', 'min:1'],
            'alg'        => ['nullable', 'string', 'max:32'],
        ]);

        $user = Auth::user();
        $user->public_key            = $data['public_key'];
        $user->key_escrow            = $data['escrow'];
        $user->key_escrow_salt       = $data['salt'];
        $user->key_escrow_nonce      = $data['nonce'];
        $user->key_escrow_ops        = $data['ops'];
        $user->key_escrow_mem        = $data['mem'];
        $user->key_escrow_alg        = $data['alg'] ?? 'argon2id';
        $user->key_escrow_updated_at = now();
        $user->save();

        return response()->json([
            'public_key' => $user->public_key,
            'updated_at' => $user->key_escrow_updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Return the authenticated user's own key escrow so a new device can unwrap
     * the private key locally (after the user enters their pincode). Returns
     * nulls when no escrow has been set up yet.
     */
    public function escrow()
    {
        $user = Auth::user();

        return response()->json([
            'public_key' => $user->public_key,
            'escrow'     => $user->key_escrow,
            'salt'       => $user->key_escrow_salt,
            'nonce'      => $user->key_escrow_nonce,
            'ops'        => $user->key_escrow_ops !== null ? (int) $user->key_escrow_ops : null,
            'mem'        => $user->key_escrow_mem !== null ? (int) $user->key_escrow_mem : null,
            'alg'        => $user->key_escrow_alg,
            'updated_at' => $user->key_escrow_updated_at?->toIso8601String(),
        ]);
    }
}
