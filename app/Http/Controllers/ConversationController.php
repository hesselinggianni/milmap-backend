<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    /**
     * List the authenticated user's direct conversations, newest activity first.
     */
    public function index(Request $request)
    {
        $userId = Auth::id();

        // Make sure the group ('channel') chat exists — and lists the user as a
        // participant — for every mission they're part of, so mission groups show
        // up here automatically instead of only after someone opens the mission's
        // Comms tab. Runs on list load (init / opening the chat list), not on the
        // hot message-poll, so the cost stays bounded by the user's mission count.
        $this->ensureMissionConversations($userId);

        $conversations = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('users.id', $userId))
            ->with(['participants:id,first_name,last_name,email,public_key,last_seen_at,settings'])
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->get();

        return response()->json([
            'conversations' => $conversations->map(
                fn (Conversation $c) => $this->present($c, $userId)
            )->values(),
        ]);
    }

    /**
     * Reconcile the mission group conversations for every mission the user
     * participates in (find-or-create + roster sync). Delegated to the Mission
     * model so the mission Comms tab and the chat list share one code path.
     */
    protected function ensureMissionConversations(int $userId): void
    {
        Mission::participatedBy($userId)
            ->get(['id', 'name', 'owner_id', 'status', 'linked_team_id'])
            ->each(fn (Mission $m) => $m->syncGroupConversation());
    }

    /**
     * Find-or-create a direct (1-on-1) conversation with another user.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $me    = Auth::id();
        $other = (int) $data['user_id'];

        if ($other === $me) {
            return response()->json(['message' => 'Cannot start a conversation with yourself.'], 422);
        }

        // Look for an existing direct conversation containing exactly these two.
        $existingId = DB::table('conversation_user as cu1')
            ->join('conversation_user as cu2', 'cu1.conversation_id', '=', 'cu2.conversation_id')
            ->join('conversations as c', 'c.id', '=', 'cu1.conversation_id')
            ->where('c.type', 'direct')
            ->where('cu1.user_id', $me)
            ->where('cu2.user_id', $other)
            ->value('cu1.conversation_id');

        if ($existingId) {
            $conversation = Conversation::with('participants:id,first_name,last_name,email,public_key,last_seen_at,settings')
                ->find($existingId);

            return response()->json([
                'conversation' => $this->present($conversation, $me),
            ]);
        }

        $conversation = Conversation::create([
            'type'       => 'direct',
            'created_by' => $me,
        ]);
        $conversation->participants()->attach([$me, $other]);
        $conversation->load('participants:id,first_name,last_name,email,public_key,last_seen_at,settings');

        return response()->json([
            'conversation' => $this->present($conversation, $me),
        ], 201);
    }

    /**
     * Show a single conversation (participant-guarded).
     */
    public function show($id)
    {
        $userId = Auth::id();

        $conversation = Conversation::with('participants:id,first_name,last_name,email,public_key,last_seen_at,settings')
            ->findOrFail($id);

        if (! $conversation->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'conversation' => $this->present($conversation, $userId),
        ]);
    }

    /**
     * Mark the conversation as read up to now for the authenticated user.
     */
    public function markRead($id)
    {
        $userId = Auth::id();

        $conversation = Conversation::findOrFail($id);
        if (! $conversation->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $now = now();
        $conversation->participants()->updateExistingPivot($userId, [
            'last_read_at' => $now,
        ]);

        // Tell the other participants (live) that this user has caught up, so
        // their read-receipt ticks can turn blue without waiting for a poll.
        try {
            broadcast(new \App\Events\ConversationRead(
                (string) $conversation->id,
                $userId,
                $now->toIso8601String()
            ))->toOthers();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[chat] read broadcast failed: ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Shape a conversation for the API, from the viewer's perspective.
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
                'id'           => $u->id,
                'name'         => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email,
                'email'        => $u->email,
                'public_key'   => $u->public_key,
                'last_seen_at' => $u->publicLastSeen(),
                // Read-receipt cursor: lets clients tick a sent message blue once
                // every other participant's last_read_at has passed it.
                'last_read_at' => ($u->pivot && $u->pivot->last_read_at)
                    ? \Illuminate\Support\Carbon::parse($u->pivot->last_read_at)->toIso8601String()
                    : null,
            ])->values(),
        ];
    }
}
