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
     * Store the account key escrow: the user's PRIVATE key, wrapped client-side.
     *
     * Two wrapping schemes coexist:
     *  • "secretbox-acct-v1" (current): wrapped with a RANDOM 32-byte unlock
     *    key that is uploaded alongside the blob and stored encrypted at rest
     *    (`encrypted` cast). Any device of the authenticated user can fetch
     *    blob + unlock key and read chat history right after login. No KDF, so
     *    salt/ops/mem are absent.
     *  • "argon2id" (legacy): wrapped with a pincode/password-derived key; the
     *    server holds only ciphertext + KDF parameters.
     *
     * Setting the escrow also promotes the wrapped keypair to the account's
     * public key, so the SAME keypair is reachable + readable on every device.
     */
    public function storeEscrow(Request $request)
    {
        $data = $request->validate([
            'public_key' => ['required', 'string', 'max:255'],
            'escrow'     => ['required', 'string', 'max:20000'],
            'nonce'      => ['required', 'string', 'max:255'],
            // KDF parameters — legacy argon2id blobs only.
            'salt'       => ['nullable', 'string', 'max:255'],
            'ops'        => ['nullable', 'integer', 'min:1', 'max:100'],
            'mem'        => ['nullable', 'integer', 'min:1'],
            'alg'        => ['nullable', 'string', 'max:32'],
            // Random account unlock key — secretbox-acct-v1 blobs only.
            'unlock_key' => ['nullable', 'string', 'max:255'],
        ]);

        $user = Auth::user();
        $user->public_key            = $data['public_key'];
        $user->key_escrow            = $data['escrow'];
        $user->key_escrow_salt       = $data['salt'] ?? null;
        $user->key_escrow_nonce      = $data['nonce'];
        $user->key_escrow_ops        = $data['ops'] ?? null;
        $user->key_escrow_mem        = $data['mem'] ?? null;
        $user->key_escrow_alg        = $data['alg'] ?? 'argon2id';
        $user->key_escrow_unlock     = $data['unlock_key'] ?? null;
        $user->key_escrow_updated_at = now();
        $user->save();

        return response()->json([
            'public_key' => $user->public_key,
            'updated_at' => $user->key_escrow_updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Return the authenticated user's own key escrow so any of their devices
     * can unwrap the private key locally. For "secretbox-acct-v1" blobs the
     * unlock key rides along, so the device can adopt the account identity
     * right after login; legacy "argon2id" blobs still need the pincode.
     * Returns nulls when no escrow has been set up yet.
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
            'unlock_key' => $user->key_escrow_unlock,
            'updated_at' => $user->key_escrow_updated_at?->toIso8601String(),
        ]);
    }
}
