<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-assisted account key for cross-device chat encryption.
 *
 * The user's X25519 private key is wrapped client-side with a RANDOM 32-byte
 * account key (full entropy — no KDF/passphrase involved). That unlock key is
 * stored here, encrypted at rest with the app key (Laravel `encrypted` cast),
 * and is released only to the authenticated account owner via
 * GET /chat/keys/escrow. Any device the user logs in on fetches blob + unlock
 * key and unwraps the private key locally — no pincode, password or QR needed.
 *
 * Trade-off vs. the old pincode/password escrow: the server now holds both the
 * wrapped key and the unlock key, so this is server-assisted encryption rather
 * than strict zero-knowledge E2EE. A database dump alone still reveals nothing
 * (the unlock key is encrypted with APP_KEY, which lives outside the DB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Laravel `encrypted` cast payload of the base64 unlock key.
            $table->text('key_escrow_unlock')->nullable()->after('key_escrow_alg');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('key_escrow_unlock');
        });
    }
};
