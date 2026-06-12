<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Twee kolommen voor share-attributie:
 *
 *  - share_uuid       : ondoorzichtige eigen-uuid die gebruikt wordt als
 *                       utm_source op deellinks. Niet de auto-increment id
 *                       zodat anderen via een referrer-keten geen user-id's
 *                       kunnen enumereren.
 *  - referred_by_id   : optioneel — wie heeft mij doorverwezen (gevuld bij
 *                       registreren als de uitnodig-link de utm_source droeg).
 *
 * Beide krijgen een index zodat het admin "leads"-overzicht en de
 * "wie heeft mij geworven"-lookup snel blijven.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'share_uuid')) {
                $t->uuid('share_uuid')->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'referred_by_id')) {
                $t->unsignedBigInteger('referred_by_id')->nullable()->after('share_uuid');
                $t->foreign('referred_by_id')->references('id')->on('users')->nullOnDelete();
                $t->index('referred_by_id');
            }
        });

        // Backfill: elke bestaande gebruiker krijgt een share_uuid zodat
        // bestaande accounts direct kunnen delen na de migratie.
        \DB::table('users')->whereNull('share_uuid')->orderBy('id')->chunkById(500, function ($users) {
            foreach ($users as $u) {
                \DB::table('users')->where('id', $u->id)->update([
                    'share_uuid' => (string) \Illuminate\Support\Str::uuid(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'referred_by_id')) {
                $t->dropForeign(['referred_by_id']);
                $t->dropColumn('referred_by_id');
            }
            if (Schema::hasColumn('users', 'share_uuid')) {
                $t->dropColumn('share_uuid');
            }
        });
    }
};
