<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Teamleden krijgen een rol (member|guest), per-lid rechten (permissions-JSON,
 * subset van wat de owner-plan toestaat) en een status (pending|active).
 *
 * `pending` = uitgenodigd maar de gebruiker moet nog de keuze-flow doorlopen
 * (huidige abonnement behouden óf opzeggen en joinen). `active` = lift mee op
 * het team-abonnement.
 *
 * Regel "één gebruiker onder max één abonnement": afgedwongen met een unique
 * index op user_id (NULL's — e-mailuitnodigingen zonder account — mogen
 * meerdere keren voorkomen; een gekoppeld account kan slechts in één team). Voor
 * de constraint dedupliceren we bestaande rijen: per user_id houden we de
 * oudste membership, de rest wordt verwijderd (feature was nog nauwelijks in
 * gebruik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->string('role', 16)->default('member')->after('user_id');
            $table->json('permissions')->nullable()->after('role');
            $table->string('status', 16)->default('active')->after('permissions');
        });

        // Dedupe: behoud per user_id de oudste membership, verwijder duplicaten,
        // zodat de unique index kan worden gezet.
        $dupes = DB::table('team_members')
            ->select('user_id')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($dupes as $userId) {
            $ids = DB::table('team_members')
                ->where('user_id', $userId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id');
            // Eerste behouden, rest verwijderen.
            DB::table('team_members')->whereIn('id', $ids->slice(1)->all())->delete();
        }

        Schema::table('team_members', function (Blueprint $table) {
            $table->unique('user_id', 'uniq_team_member_user');
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropUnique('uniq_team_member_user');
            $table->dropColumn(['role', 'permissions', 'status']);
        });
    }
};
