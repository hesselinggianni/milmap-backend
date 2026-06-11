<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the columns the prullenbak (trash) feature needs.
 *
 *  - `deleted_at` on `maps`, `missions`, `route_maps` so destroy() becomes a
 *     soft-delete via the Eloquent SoftDeletes trait. The trash view lists rows
 *     where deleted_at is not null; the daily `trash:purge` command force-deletes
 *     anything older than 60 days.
 *
 *  - `archived_at` on the `conversation_user` pivot. Chats can't use a global
 *     soft-delete (deleting a 1:1 conversation can't punish the other person),
 *     so "deleting a chat" really means hiding it for THIS participant. The
 *     same purge job removes the pivot row entirely after 60 days, which is
 *     equivalent to "leaving the conversation for good" from that user's side.
 *
 *  Each column is indexed so the trash listing and the purge sweep stay cheap.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['maps', 'missions', 'route_maps'] as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes()->index();
                });
            }
        }

        if (Schema::hasTable('conversation_user') && ! Schema::hasColumn('conversation_user', 'archived_at')) {
            Schema::table('conversation_user', function (Blueprint $t) {
                $t->timestamp('archived_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['maps', 'missions', 'route_maps'] as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }

        if (Schema::hasTable('conversation_user') && Schema::hasColumn('conversation_user', 'archived_at')) {
            Schema::table('conversation_user', function (Blueprint $t) {
                $t->dropColumn('archived_at');
            });
        }
    }
};
