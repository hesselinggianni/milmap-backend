<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Comms for a single mission: the participant roster (with public keys for
 * sealed-box encryption) and the one group ('channel') conversation that every
 * participant shares. 1-on-1 chats with individual participants are created
 * through the normal ChatController endpoints.
 *
 * Privacy: access is gated on Mission::hasAccess, and the group conversation's
 * participant set is kept in sync with the mission roster. An admin who is not
 * a mission participant has no access here and is never attached to the group —
 * so they cannot read it. 1-on-1 chats between two other participants remain
 * unreadable to everyone else (enforced by the per-conversation participant
 * guard in ConversationController / the broadcast channel).
 */
class MissionCommsController extends Controller
{
    /**
     * The mission's participant roster, including each user's public key so the
     * client can seal a per-recipient box for group messages.
     */
    public function participants(Request $request, string $missionId)
    {
        $mission = Mission::findOrFail($missionId);
        $userId  = Auth::id();

        if (! $mission->hasAccess($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $ids   = $mission->participantUserIds();
        $users = User::whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name', 'email', 'public_key']);

        return response()->json([
            'participants' => $users->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email,
                'email'      => $u->email,
                'public_key' => $u->public_key,
                'is_me'      => $u->id === $userId,
            ])->values(),
        ]);
    }

    /**
     * Find-or-create the mission's group conversation and sync its participant
     * set to the current mission roster, then return it for the viewer.
     */
    public function conversation(Request $request, string $missionId)
    {
        $mission = Mission::findOrFail($missionId);
        $userId  = Auth::id();

        if (! $mission->hasAccess($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $conversation = Conversation::where('mission_id', $mission->id)
            ->where('type', 'channel')
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'type'       => 'channel',
                'name'       => $mission->name ?: 'Missie',
                'mission_id' => $mission->id,
                'created_by' => $mission->owner_id,
            ]);
        } elseif ($conversation->name !== ($mission->name ?: 'Missie')) {
            // Keep the group title in step with the mission name.
            $conversation->update(['name' => $mission->name ?: 'Missie']);
        }

        // Keep the group roster aligned with mission membership.
        $conversation->participants()->sync($mission->participantUserIds());

        $conversation->load('participants:id,first_name,last_name,email,public_key');

        return response()->json([
            'conversation' => $this->present($conversation, $userId),
        ]);
    }

    /**
     * Shape a conversation for the API, from the viewer's perspective.
     * Mirrors ConversationController::present.
     */
    protected function present(Conversation $c, int $viewerId): array
    {
        $others = $c->participants->where('id', '!=', $viewerId)->values();
        $me     = $c->participants->firstWhere('id', $viewerId);

        $title = $c->type === 'direct'
            ? trim(($others[0]->first_name ?? '') . ' ' . ($others[0]->last_name ?? '')) ?: ($others[0]->email ?? 'Onbekend')
            : ($c->name ?? 'Groep');

        $lastReadAt = $me?->pivot?->last_read_at;
        $unread = Message::where('conversation_id', $c->id)
            ->where('sender_id', '!=', $viewerId)
            ->when($lastReadAt, fn ($q) => $q->where('created_at', '>', $lastReadAt))
            ->count();

        return [
            'id'              => $c->id,
            'type'            => $c->type,
            'title'           => $title,
            'mission_id'      => $c->mission_id,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'unread'          => $unread,
            'participants'    => $c->participants->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email,
                'email'      => $u->email,
                'public_key' => $u->public_key,
            ])->values(),
        ];
    }
}
