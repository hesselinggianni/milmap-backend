<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Account resetten: alle eigen inhoud weg, het account zelf blijft.
 *
 * Bedoeld voor gedeelde/zakelijke apparaten (MilMap Connect: een iPad die naar
 * de volgende leerling gaat) en voor gebruikers die opnieuw willen beginnen.
 * Uitloggen wist bewust niets meer; dit is de enige knop die dat wel doet.
 *
 * Weg: eigen kaarten (incl. prullenbak, met waypoints/meldingen/deellinks via
 * ON DELETE CASCADE), routekaarten, missies, activiteiten, media/bijlagen,
 * gegenereerde routes, sjablonen, oefenterreinen, zoekgeschiedenis,
 * laagvoorkeuren, live-locatie, meldingen en alle chats.
 *
 * Blijft: het account, profiel, wachtwoord/apparaatlogin, abonnement, E2EE-
 * sleutels, Connect-lidmaatschap en toegang tot kaarten/missies van anderen.
 * Bijdragen aan andermans kaarten blijven staan: die horen bij de eigenaar.
 */
class AccountReset
{
    public function reset(User $user): array
    {
        $id = $user->id;
        $files = [];

        $counts = DB::transaction(function () use ($id, &$files) {
            $mapIds = DB::table('maps')->where('owner_id', $id)->pluck('id');

            // Bestanden pas ná de commit weghalen; de rijen gaan mee via cascade.
            DB::table('map_waypoint_images')
                ->whereIn('map_waypoint_id', DB::table('map_waypoints')->whereIn('map_id', $mapIds)->select('id'))
                ->pluck('path')
                ->each(function ($path) use (&$files) { $files[] = ['public', $path]; });
            DB::table('user_uploads')->where('user_id', $id)->get(['disk', 'path'])
                ->each(function ($u) use (&$files) { $files[] = [$u->disk ?: 'public', $u->path]; });

            $counts = [
                'maps'       => DB::table('maps')->whereIn('id', $mapIds)->delete(),
                'route_maps' => DB::table('route_maps')->where('owner_id', $id)->delete(),
                'missions'   => DB::table('missions')->where('owner_id', $id)->delete(),
                'activities' => DB::table('activities')->where('user_id', $id)->delete(),
                'media'      => DB::table('user_uploads')->where('user_id', $id)->delete(),
            ];

            foreach (['generated_routes', 'search_histories', 'map_layer_prefs', 'user_locations', 'app_notifications'] as $table) {
                DB::table($table)->where('user_id', $id)->delete();
            }
            foreach (['ogroup_templates', 'mission_task_templates', 'exercise_sites'] as $table) {
                DB::table($table)->where('owner_id', $id)->delete();
            }

            // Chats: de gebruiker verlaat elk gesprek; een gesprek zonder deelnemers verdwijnt.
            $conversationIds = DB::table('conversation_user')->where('user_id', $id)->pluck('conversation_id');
            DB::table('conversation_user')->where('user_id', $id)->delete();
            DB::table('conversations')->whereIn('id', $conversationIds)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('conversation_user')->whereColumn('conversation_user.conversation_id', 'conversations.id'))
                ->delete();
            DB::table('chat_requests')->where('requester_id', $id)->orWhere('recipient_id', $id)->delete();
            DB::table('chat_invites')->where('inviter_id', $id)->delete();
            $counts['chats'] = $conversationIds->count();

            // Signaal voor apps met een offline kopie: wis die ook (zie users.data_reset_at).
            DB::table('users')->where('id', $id)->update(['data_reset_at' => now()]);

            return $counts;
        });

        foreach ($files as [$disk, $path]) {
            try { if ($path) Storage::disk($disk)->delete($path); } catch (\Throwable $e) { /* best-effort */ }
        }

        Log::info('[account] gereset', ['user_id' => $id] + $counts);

        return $counts;
    }

    /**
     * Alleen de werkgegevens van één organisatie (kaarten, routes, missies met
     * connect_organization_id). Voor een persoonlijk account dat lid is van
     * Connect: de organisatie beheert haar eigen data, nooit iemands privédata.
     */
    public function resetOrganizationData(User $user, int $organizationId): array
    {
        $files = [];
        $counts = DB::transaction(function () use ($user, $organizationId, &$files) {
            $mapIds = DB::table('maps')->where('owner_id', $user->id)->where('connect_organization_id', $organizationId)->pluck('id');
            DB::table('map_waypoint_images')
                ->whereIn('map_waypoint_id', DB::table('map_waypoints')->whereIn('map_id', $mapIds)->select('id'))
                ->pluck('path')
                ->each(function ($path) use (&$files) { $files[] = $path; });

            $counts = [
                'maps'       => DB::table('maps')->whereIn('id', $mapIds)->delete(),
                'route_maps' => DB::table('route_maps')->where('owner_id', $user->id)->where('connect_organization_id', $organizationId)->delete(),
                'missions'   => DB::table('missions')->where('owner_id', $user->id)->where('connect_organization_id', $organizationId)->delete(),
            ];
            DB::table('users')->where('id', $user->id)->update(['data_reset_at' => now()]);

            return $counts;
        });

        foreach ($files as $path) {
            try { if ($path) Storage::disk('public')->delete($path); } catch (\Throwable $e) { /* best-effort */ }
        }

        Log::info('[account] organisatiedata gereset', ['user_id' => $user->id, 'organization_id' => $organizationId] + $counts);

        return $counts;
    }
}
