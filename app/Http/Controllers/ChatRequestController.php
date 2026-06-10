<?php

namespace App\Http\Controllers;

use App\Mail\ChatRequestMail;
use App\Models\ChatInvite;
use App\Models\ChatRequest;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Chat connection requests between two existing accounts.
 *
 * Looking someone up and wanting to chat no longer opens a conversation
 * immediately: it sends them a request they must accept. A conversation is
 * only created once accepted; declining means the requester cannot chat with
 * that person.
 */
class ChatRequestController extends Controller
{
    /**
     * Send a chat request to an existing user (or short-circuit to an open
     * conversation when the two are already connected / cross-requested).
     * POST /chat/requests
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $me    = Auth::id();
        $other = (int) $data['user_id'];

        if ($other === $me) {
            return response()->json(['message' => 'Je kunt geen verzoek naar jezelf sturen.'], 422);
        }

        // Already have a 1-on-1 conversation → just open it.
        if ($conversationId = $this->findDirectConversation($me, $other)) {
            return response()->json(['status' => 'connected', 'conversation_id' => $conversationId]);
        }

        // The other person already requested ME → accept it and connect.
        $incoming = ChatRequest::where('requester_id', $other)
            ->where('recipient_id', $me)
            ->where('status', 'pending')
            ->first();
        if ($incoming) {
            $incoming->update(['status' => 'accepted', 'responded_at' => now()]);
            $cid = $this->ensureDirectConversation($me, $other);
            return response()->json(['status' => 'connected', 'conversation_id' => $cid]);
        }

        // A previously accepted request exists (either direction) but no
        // conversation — heal it by (re)creating the conversation.
        $accepted = ChatRequest::where('status', 'accepted')
            ->where(function ($q) use ($me, $other) {
                $q->where(fn ($w) => $w->where('requester_id', $me)->where('recipient_id', $other))
                    ->orWhere(fn ($w) => $w->where('requester_id', $other)->where('recipient_id', $me));
            })
            ->first();
        if ($accepted) {
            $cid = $this->ensureDirectConversation($me, $other);
            return response()->json(['status' => 'connected', 'conversation_id' => $cid]);
        }

        // Otherwise create / refresh a pending outgoing request and notify.
        $req = ChatRequest::updateOrCreate(
            ['requester_id' => $me, 'recipient_id' => $other],
            ['status' => 'pending', 'responded_at' => null]
        );

        $this->notifyRecipient($other);

        return response()->json(['status' => 'requested', 'request_id' => $req->id]);
    }

    /**
     * Pending chat requests addressed to the authenticated user.
     * GET /chat/requests/incoming
     */
    public function incoming()
    {
        $requests = ChatRequest::where('recipient_id', Auth::id())
            ->where('status', 'pending')
            ->with(['requester:id,first_name,last_name,email'])
            ->latest()
            ->get()
            ->map(fn ($r) => [
                'id'         => $r->id,
                'name'       => $r->requester?->full_name ?: ($r->requester?->email ?? 'Iemand'),
                'email'      => $r->requester?->email,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return response()->json(['requests' => $requests]);
    }

    /**
     * Outgoing, not-yet-answered chat connections of the authenticated user —
     * both requests to existing accounts and e-mail invites for people without
     * an account. The frontend shows these as "pending" chats so the inviter
     * sees who they reached out to before it's accepted.
     * GET /chat/pending
     */
    public function pending()
    {
        $me = Auth::id();

        $requests = ChatRequest::where('requester_id', $me)
            ->where('status', 'pending')
            ->with(['recipient:id,first_name,last_name,email'])
            ->latest()
            ->get()
            ->map(fn ($r) => [
                'id'         => 'req:' . $r->id,
                'kind'       => 'request',
                'user_id'    => $r->recipient_id,
                'name'       => $r->recipient?->full_name ?: ($r->recipient?->email ?? 'Onbekend'),
                'email'      => $r->recipient?->email,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        $invites = ChatInvite::where('inviter_id', $me)
            ->where('status', 'pending')
            ->latest()
            ->get()
            ->map(fn ($i) => [
                'id'         => 'inv:' . $i->id,
                'kind'       => 'invite',
                'user_id'    => null,
                'name'       => $i->email,
                'email'      => $i->email,
                'created_at' => $i->created_at?->toIso8601String(),
            ]);

        $pending = $requests->concat($invites)
            ->sortByDesc('created_at')
            ->values();

        return response()->json(['pending' => $pending]);
    }

    /**
     * Accept a chat request → open the direct conversation.
     * POST /chat/requests/{id}/accept
     */
    public function accept($id)
    {
        $req = ChatRequest::findOrFail($id);

        if ((int) $req->recipient_id !== Auth::id()) {
            abort(403, 'Dit verzoek is niet voor jou bestemd.');
        }
        if ($req->status === 'declined') {
            return response()->json(['error' => 'Dit verzoek is al afgewezen.'], 422);
        }

        $req->update(['status' => 'accepted', 'responded_at' => now()]);
        $conversationId = $this->ensureDirectConversation((int) $req->recipient_id, (int) $req->requester_id);

        return response()->json([
            'status'          => 'accepted',
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * Decline a chat request → the requester cannot chat with this user.
     * POST /chat/requests/{id}/decline
     */
    public function decline($id)
    {
        $req = ChatRequest::findOrFail($id);

        if ((int) $req->recipient_id !== Auth::id()) {
            abort(403, 'Dit verzoek is niet voor jou bestemd.');
        }

        if ($req->status === 'pending') {
            $req->update(['status' => 'declined', 'responded_at' => now()]);
        }

        return response()->json(['status' => 'declined']);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    protected function notifyRecipient(int $recipientId): void
    {
        try {
            $recipient = User::find($recipientId);
            if (! $recipient || ! $recipient->email) {
                return;
            }

            // Anti-spam: hoogstens 1 chatverzoek-mail per uur naar hetzelfde adres.
            $throttleKey = 'chat-mail:' . sha1(mb_strtolower($recipient->email));
            if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
                return;
            }

            $me   = Auth::user();
            $name = $me->full_name ?: ($me->first_name ?? 'Een Milmap-gebruiker');
            $url  = rtrim(config('app.frontend_url', 'https://app.milmap.nl'), '/') . '/hub';

            Mail::to($recipient->email)->send(new ChatRequestMail($name, $url));
            RateLimiter::hit($throttleKey, 3600); // 1 uur
        } catch (\Throwable $e) {
            Log::warning('[chat] request email failed: ' . $e->getMessage());
        }
    }

    protected function findDirectConversation(int $a, int $b): ?string
    {
        return DB::table('conversation_user as cu1')
            ->join('conversation_user as cu2', 'cu1.conversation_id', '=', 'cu2.conversation_id')
            ->join('conversations as c', 'c.id', '=', 'cu1.conversation_id')
            ->where('c.type', 'direct')
            ->where('cu1.user_id', $a)
            ->where('cu2.user_id', $b)
            ->value('cu1.conversation_id');
    }

    protected function ensureDirectConversation(int $a, int $b): ?string
    {
        if ($a === $b) {
            return null;
        }

        if ($existing = $this->findDirectConversation($a, $b)) {
            return $existing;
        }

        $conversation = Conversation::create([
            'type'       => 'direct',
            'created_by' => $a,
        ]);
        $conversation->participants()->attach([$a, $b]);

        return $conversation->id;
    }
}
