<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zero-knowledge key escrow.
 *
 * Lets the SAME logged-in user read their own E2EE chat history on ANY device.
 * The user's X25519 PRIVATE key is wrapped (encrypted) client-side with a key
 * derived from a personal pincode/passphrase (Argon2id), then the opaque blob
 * is stored here. The server only ever sees ciphertext + KDF parameters — it
 * cannot derive the pincode and therefore cannot read the private key, so the
 * end-to-end guarantee is preserved. A new device fetches this blob and unwraps
 * it locally after the user enters the pincode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // crypto_secretbox ciphertext of the private key (base64).
            $table->text('key_escrow')->nullable()->after('public_key');
            // Argon2id salt (base64) used to derive the wrapping key.
            $table->text('key_escrow_salt')->nullable()->after('key_escrow');
            // crypto_secretbox nonce (base64).
            $table->text('key_escrow_nonce')->nullable()->after('key_escrow_salt');
            // Argon2id parameters, stored so the blob stays decryptable if the
            // client defaults ever change.
            $table->unsignedInteger('key_escrow_ops')->nullable()->after('key_escrow_nonce');
            $table->unsignedBigInteger('key_escrow_mem')->nullable()->after('key_escrow_ops');
            $table->string('key_escrow_alg', 32)->nullable()->after('key_escrow_mem');
            // When the escrow was last (re)set — surfaced in the UI.
            $table->timestamp('key_escrow_updated_at')->nullable()->after('key_escrow_alg');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'key_escrow',
                'key_escrow_salt',
                'key_escrow_nonce',
                'key_escrow_ops',
                'key_escrow_mem',
                'key_escrow_alg',
                'key_escrow_updated_at',
            ]);
        });
    }
};
