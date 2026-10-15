<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Admin: de inhoud van de ene gebruiker overzetten naar een andere.
 *
 * Alleen eigendom wordt omgehangen (owner_id / user_id / created_by); er gaat
 * geen melding of mail uit en de ontvanger ziet de data gewoon in zijn account.
 * Bewust NIET verplaatst: abonnement/facturatie, sessies, teamlidmaatschappen,
 * chat (E2EE, gebonden aan de sleutels van de afzender) en notificaties.
 */
class AdminDataTransferController extends Controller
{
    /** tabel => kolommen die naar de nieuwe eigenaar wijzen. */
    private const TABLES = [
        'maps'                   => ['owner_id'],
        'route_maps'             => ['owner_id'],
        'missions'               => ['owner_id'],
        'mission_task_templates' => ['owner_id'],
        'ogroup_templates'       => ['owner_id'],
        'exercise_sites'         => ['owner_id'],
        'map_waypoints'          => ['user_id'],
        'map_waypoint_images'    => ['user_id'],
        'map_shares'             => ['created_by'],
        'mission_tasks'          => ['created_by'],
        'mission_tracks'         => ['user_id'],
        'reports'                => ['user_id'],
        'report_revisions'       => ['user_id'],
        'nine_lines'             => ['user_id'],
        'nine_line_revisions'    => ['user_id'],
        'activities'             => ['user_id'],
        'generated_routes'       => ['user_id'],
        'locations'              => ['user_id'],
        'user_uploads'           => ['user_id'],
        'exercise_submissions'   => ['user_id'],
    ];

    /** GET /admin/users/{userId}/transfer-data — aantallen per soort (voorvertoning). */
    public function preview($userId)
    {
        $user = User::findOrFail($userId);

        return response()->json(['counts' => $this->counts($user->id)]);
    }

    /** POST /admin/users/{userId}/transfer-data  { target: id of e-mail } */
    public function transfer(Request $request, $userId)
    {
        $data = $request->validate(['target' => ['required', 'string', 'max:255']]);

        $source = User::findOrFail($userId);
        $target = ctype_digit($data['target'])
            ? User::find((int) $data['target'])
            : User::where('email', trim($data['target']))->first();

        if (! $target) {
            return response()->json(['message' => 'Doelgebruiker niet gevonden.'], 404);
        }
        if ($target->id === $source->id) {
            return response()->json(['message' => 'Bron en doel zijn dezelfde gebruiker.'], 422);
        }

        $moved = [];
        DB::transaction(function () use ($source, $target, &$moved) {
            foreach (self::TABLES as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        continue;
                    }
                    // IGNORE: een unieke index (bv. user_id+client_request_id)
                    // mag de rest niet blokkeren; zo'n rij blijft bij de bron.
                    $n = DB::affectingStatement(
                        "UPDATE IGNORE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?",
                        [$target->id, $source->id]
                    );
                    if ($n > 0) {
                        $moved["{$table}.{$column}"] = $n;
                    }
                }
            }
        });

        Log::info('[admin] data overgezet', [
            'admin_id' => $request->user()?->id, 'from' => $source->id, 'to' => $target->id, 'moved' => $moved,
        ]);

        return response()->json([
            'message' => "Data van {$source->name} is overgezet naar {$target->name}.",
            'moved'   => $moved,
        ]);
    }

    private function counts(int $userId): array
    {
        $out = [];
        foreach (self::TABLES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $n = DB::table($table)->where($column, $userId)->count();
                if ($n > 0) {
                    $out["{$table}.{$column}"] = $n;
                }
            }
        }

        return $out;
    }
}
