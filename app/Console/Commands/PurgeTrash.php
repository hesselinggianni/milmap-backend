<?php

namespace App\Console\Commands;

use App\Http\Controllers\TrashController;
use App\Models\Conversation;
use App\Models\Map;
use App\Models\Mission;
use App\Models\RouteMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Definitively remove items that have been in the trash longer than the grace
 * window. Scheduled daily — see bootstrap/app.php.
 *
 * What "purge" means per type:
 *   - Map / Mission / RouteMap: forceDelete() — gone from the DB.
 *   - Chat: drop the conversation_user pivot row (= the user has fully left).
 *           If no participants remain afterwards the Conversation itself is
 *           deleted so we don't accumulate orphans.
 *
 * The command prints counts and is idempotent — safe to run by hand.
 */
class PurgeTrash extends Command
{
    protected $signature = 'trash:purge {--dry : List what would be purged, do not delete anything}';
    protected $description = 'Definitief verwijder items uit de prullenbak die ouder zijn dan 60 dagen.';

    public function handle(): int
    {
        $cutoff = now()->subDays(TrashController::PURGE_DAYS);
        $dry    = (bool) $this->option('dry');

        $this->line('Cutoff: ' . $cutoff->toDateTimeString() . ($dry ? ' [DRY RUN]' : ''));

        // ── Maps / Missions / RouteMaps ───────────────────────
        $counts = [
            'maps'       => Map::onlyTrashed()->where('deleted_at', '<', $cutoff)->count(),
            'missions'   => Mission::onlyTrashed()->where('deleted_at', '<', $cutoff)->count(),
            'route_maps' => RouteMap::onlyTrashed()->where('deleted_at', '<', $cutoff)->count(),
            'media'      => \App\Models\UserUpload::onlyTrashed()->where('deleted_at', '<', $cutoff)->count(),
        ];

        if (! $dry) {
            // forceDelete() in a chunked loop so model events fire (which lets
            // SoftDeletes-aware relations cascade properly).
            Map::onlyTrashed()->where('deleted_at', '<', $cutoff)->chunkById(200, function ($chunk) {
                $chunk->each->forceDelete();
            });
            Mission::onlyTrashed()->where('deleted_at', '<', $cutoff)->chunkById(200, function ($chunk) {
                $chunk->each->forceDelete();
            });
            RouteMap::onlyTrashed()->where('deleted_at', '<', $cutoff)->chunkById(200, function ($chunk) {
                $chunk->each->forceDelete();
            });
            // Media — naast de DB-regel ook het fysieke bestand opruimen zodat
            // de schijf daadwerkelijk vrijkomt.
            \App\Models\UserUpload::onlyTrashed()->where('deleted_at', '<', $cutoff)->chunkById(200, function ($chunk) {
                foreach ($chunk as $u) {
                    try {
                        $disk = \Illuminate\Support\Facades\Storage::disk($u->disk ?: 'public');
                        if ($disk->exists($u->path)) $disk->delete($u->path);
                    } catch (\Throwable $e) { /* best-effort */ }
                    $u->forceDelete();
                }
            });
        }

        // ── Chats (per-user archive) ──────────────────────────
        $chatRows = DB::table('conversation_user')
            ->whereNotNull('archived_at')
            ->where('archived_at', '<', $cutoff)
            ->get(['conversation_id', 'user_id']);

        if (! $dry) {
            DB::table('conversation_user')
                ->whereNotNull('archived_at')
                ->where('archived_at', '<', $cutoff)
                ->delete();

            // Drop conversations whose last participant just left.
            $orphans = $chatRows->pluck('conversation_id')->unique();
            foreach ($orphans as $cid) {
                $left = DB::table('conversation_user')->where('conversation_id', $cid)->count();
                if ($left === 0) {
                    Conversation::where('id', $cid)->delete();
                }
            }
        }

        $this->table(
            ['type', 'purged'],
            [
                ['maps',       $counts['maps']],
                ['missions',   $counts['missions']],
                ['route_maps', $counts['route_maps']],
                ['media',      $counts['media']],
                ['chats',      $chatRows->count()],
            ]
        );

        return self::SUCCESS;
    }
}
