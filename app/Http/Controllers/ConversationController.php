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

        // Exclude conversations the caller has archived (= deleted for them).
        // Other participants still see the conversation; restore from /trash.
        $conversations = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('users.id', $userId)
                ->whereNull('conversation_user.archived_at'))
            ->with(['participants:id,first_name,last_name,avatar_path,email,public_key,last_seen_at,settings'])
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
            $conversation = Conversation::with('participants:id,first_name,last_name,avatar_path,email,public_key,last_seen_at,settings')
                ->find($existingId);

            return response()->json([
                'conversation' => $this->present($conversation, $me),
            ]);
        }

        // Connected-gate: a direct conversation may only be *opened* with someone
        // you're already connected to — a mission/map teammate, an existing
        // contact, or someone whose chat request was accepted. Strangers must go
        // through the request flow (POST /chat/requests) first; otherwise this
        // endpoint would let anyone DM anyone by guessing a user_id, bypassing the
        // accept/decline gate entirely.
        if (! $this->alreadyConnected($me, $other)) {
            return response()->json([
                'message'       => 'Stuur eerst een chatverzoek voordat je een gesprek kunt starten.',
                'needs_request' => true,
            ], 403);
        }

        $conversation = Conversation::create([
            'type'       => 'direct',
            'created_by' => $me,
        ]);
        $conversation->participants()->attach([$me, $other]);
        $conversation->load('participants:id,first_name,last_name,avatar_path,email,public_key,last_seen_at,settings');

        return response()->json([
            'conversation' => $this->present($conversation, $me),
        ], 201);
    }

    /**
     * Are these two users already connected — i.e. allowed to open a direct
     * conversation without sending a chat request first?
     *
     * Connected means EITHER they already share at least one conversation (a
     * mission/map group puts teammates in the same channel; an existing direct
     * chat obviously counts), OR there's an accepted chat request between them in
     * either direction. Strangers match neither and must use the request flow.
     */
    protected function alreadyConnected(int $a, int $b): bool
    {
        $shareConversation = DB::table('conversation_user as cu1')
            ->join('conversation_user as cu2', 'cu1.conversation_id', '=', 'cu2.conversation_id')
            ->where('cu1.user_id', $a)
            ->where('cu2.user_id', $b)
            ->exists();

        if ($shareConversation) {
            return true;
        }

        return \App\Models\ChatRequest::where('status', 'accepted')
            ->where(function ($q) use ($a, $b) {
                $q->where(fn ($w) => $w->where('requester_id', $a)->where('recipient_id', $b))
                    ->orWhere(fn ($w) => $w->where('requester_id', $b)->where('recipient_id', $a));
            })
            ->exists();
    }

    /**
     * Show a single conversation (participant-guarded).
     */
    public function show($id)
    {
        $userId = Auth::id();

        $conversation = Conversation::with('participants:id,first_name,last_name,avatar_path,email,public_key,last_seen_at,settings')
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
     * Archive a conversation for THIS user only — the inverse of "delete" for
     * chats. The other participants keep seeing the conversation normally.
     * The user can restore it from /trash within 60 days; after that the
     * PurgeTrash command drops their pivot row (= they leave for good).
     */
    public function archive($id)
    {
        $userId = Auth::id();

        $conversation = Conversation::findOrFail($id);
        if (! $conversation->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $conversation->participants()->updateExistingPivot($userId, [
            'archived_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Mute (no push / no notifications) for THIS user. Body:
     *   { hours: int? }   default = 8 (until tomorrow morning)
     * Set hours=0 to unmute. Use a sentinel of 9999 hours for "forever";
     * frontend just labels it "Gedempt".
     */
    public function mute(Request $request, $id)
    {
        $userId = Auth::id();
        $conv = Conversation::findOrFail($id);
        if (! $conv->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $hours = (int) ($request->input('hours', 8));
        $until = $hours <= 0 ? null : now()->addHours(min($hours, 24 * 365 * 10));
        $conv->participants()->updateExistingPivot($userId, ['muted_until' => $until]);
        return response()->json(['ok' => true, 'muted_until' => optional($until)->toIso8601String()]);
    }

    /**
     * Favoriet markeren / weghalen voor deze gebruiker.
     */
    public function favorite($id)
    {
        $userId = Auth::id();
        $conv = Conversation::findOrFail($id);
        if (! $conv->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $row = DB::table('conversation_user')
            ->where('conversation_id', $id)->where('user_id', $userId)->first();
        $next = $row && $row->favorited_at ? null : now();
        $conv->participants()->updateExistingPivot($userId, ['favorited_at' => $next]);
        return response()->json(['ok' => true, 'favorited' => (bool) $next]);
    }

    /**
     * Markeer als ongelezen: zet last_read_at terug naar net vóór het laatste
     * bericht zodat de unread-teller opnieuw aanslaat. Voor de UI is dit een
     * blauw bolletje naast de chat.
     */
    public function markUnread($id)
    {
        $userId = Auth::id();
        $conv = Conversation::findOrFail($id);
        if (! $conv->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        // Belangrijk: de unread-teller (zie present()) telt alléén berichten
        // van ANDEREN (sender_id != viewer). Zet de cutoff dus net vóór het
        // laatste ONTVANGEN bericht — niet vóór het laatste bericht überhaupt.
        // Stuurde jij zelf het laatste bericht, dan zou een cutoff op dat
        // bericht de teller op 0 laten staan en sprong de chat meteen weer op
        // "gelezen" in het overzicht.
        $lastIncoming = DB::table('messages')->where('conversation_id', $id)
            ->where('sender_id', '!=', $userId)
            ->orderByDesc('created_at')->value('created_at');
        $cutoff = $lastIncoming
            ? \Illuminate\Support\Carbon::parse($lastIncoming)->subSecond()
            : now()->subSecond();
        $conv->participants()->updateExistingPivot($userId, ['last_read_at' => $cutoff]);
        return response()->json(['ok' => true]);
    }

    /**
     * Verlaat een groepschat. Voor de groep verdwijn ik direct uit de roster
     * en blijven mijn (sealed) historie-rijen staan zodat anderen ze nog
     * kunnen ontcijferen. Voor een 1-op-1 chat gedragen we ons als "archive"
     * — een 1-op-1 kun je niet "verlaten", de andere persoon moet ook in z'n
     * eigen lijst kunnen blijven zien.
     */
    public function leave($id)
    {
        $userId = Auth::id();
        $conv = Conversation::findOrFail($id);
        if (! $conv->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($conv->type === 'direct') {
            $conv->participants()->updateExistingPivot($userId, ['archived_at' => now()]);
            return response()->json(['ok' => true, 'mode' => 'archived']);
        }

        // Group / channel — drop the pivot row entirely.
        DB::table('conversation_user')
            ->where('conversation_id', $id)->where('user_id', $userId)->delete();

        // Tell live clients so the participant list updates without a refresh.
        try {
            if (class_exists(\App\Events\ParticipantLeft::class)) {
                broadcast(new \App\Events\ParticipantLeft((string) $id, $userId))->toOthers();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[chat] leave broadcast failed: ' . $e->getMessage());
        }

        return response()->json(['ok' => true, 'mode' => 'left']);
    }

    /**
     * "Wis chat" — verberg álle eerdere berichten voor DEZE gebruiker. We
     * verwijderen niets server-side (de andere deelnemer heeft zijn eigen
     * sealed copy en moet die kunnen blijven lezen); de client filtert
     * messages met created_at <= cleared_at weg uit hun thread.
     */
    public function clear($id)
    {
        $userId = Auth::id();
        $conv = Conversation::findOrFail($id);
        if (! $conv->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $conv->participants()->updateExistingPivot($userId, ['cleared_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /**
     * Blokkeer de tegenstander van een 1-op-1 chat. User-level: stuurt ook
     * toekomstige chat-requests van die persoon naar /dev/null. Op groeps-
     * chats geeft het 422 — daar moet je verlaten in plaats van blokkeren.
     */
    public function block($id)
    {
        $me = Auth::id();
        $conv = Conversation::findOrFail($id);
        if (! $conv->hasParticipant($me)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($conv->type !== 'direct') {
            return response()->json([
                'message' => 'Een groepschat kun je niet blokkeren — verlaat hem in plaats daarvan.',
            ], 422);
        }
        $other = DB::table('conversation_user')
            ->where('conversation_id', $id)->where('user_id', '!=', $me)->value('user_id');
        if (! $other) {
            return response()->json(['message' => 'Geen tegenstander gevonden.'], 422);
        }
        DB::table('user_blocks')->updateOrInsert(
            ['blocker_id' => $me, 'blocked_id' => $other],
            ['created_at' => now(), 'updated_at' => now()],
        );
        // Archiveer de chat automatisch zodat hij uit de lijst verdwijnt.
        $conv->participants()->updateExistingPivot($me, ['archived_at' => now()]);
        return response()->json(['ok' => true, 'blocked_user_id' => $other]);
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

        // Per-user state uit de conversation_user pivot — long-press menu UI.
        $mutedUntil  = $me?->pivot?->muted_until;
        $favoritedAt = $me?->pivot?->favorited_at;
        $clearedAt   = $me?->pivot?->cleared_at;

        // Voor 1-op-1 chats: toon de foto van de ander als gespreks-avatar.
        // Groepen houden hun groeps-icoon (avatar blijft null).
        $avatar = $c->type === 'direct' ? ($others[0]->avatar_url ?? null) : null;

        return [
            'id'              => $c->id,
            'type'            => $c->type,
            'title'           => $title,
            'avatar'          => $avatar,
            'mission_id'      => $c->mission_id,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'unread'          => $unread,
            'muted_until'     => $mutedUntil  ? \Illuminate\Support\Carbon::parse($mutedUntil)->toIso8601String()  : null,
            'favorited_at'    => $favoritedAt ? \Illuminate\Support\Carbon::parse($favoritedAt)->toIso8601String() : null,
            'cleared_at'      => $clearedAt   ? \Illuminate\Support\Carbon::parse($clearedAt)->toIso8601String()   : null,
            'participants'    => $c->participants->map(fn (User $u) => [
                'id'           => $u->id,
                'name'         => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email,
                'email'        => $u->email,
                'avatar_url'   => $u->avatar_url,
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
