<?php

namespace App\Http\Controllers;

use App\Models\ChatInvite;
use App\Models\ChatRequest;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\MapCollaborator;
use App\Models\MissionCollaborator;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InvitationController extends Controller
{
    public function __construct(protected InvitationService $service)
    {
    }

    /**
     * Public preview of an invitation (used by the /invite/{token} page before
     * the recipient is logged in / registered).
     * GET /api/v1/invitations/token/{token}
     */
    public function preview($token)
    {
        $invite = Invitation::where('token', $token)
            ->with(['invitable', 'inviter:id,first_name,last_name,email'])
            ->first();

        if (! $invite) {
            return response()->json(['error' => 'Uitnodiging niet gevonden'], 404);
        }

        return response()->json([
            'invitation' => [
                'token'          => $invite->token,
                'email'          => $invite->email,
                'status'         => $invite->status,
                'role'           => $invite->role,
                'resource_type'  => $invite->resourceType(),
                'resource_label' => $invite->resourceLabel(),
                'resource_title' => $invite->resourceTitle(),
                'resource_id'    => $invite->invitable_id,
                'invited_by'     => $invite->inviter?->full_name,
                'user_exists'    => User::where('email', $invite->email)->exists(),
                'expired'        => $invite->isExpired(),
            ],
        ]);
    }

    /**
     * Accept an invitation as the authenticated user.
     * POST /api/v1/invitations/token/{token}/accept
     */
    public function accept($token)
    {
        $invite = Invitation::where('token', $token)->firstOrFail();
        $user   = Auth::user();

        if (! $this->addressedTo($invite, $user)) {
            abort(403, 'Deze uitnodiging is niet voor jou bestemd.');
        }
        if (! $invite->isPending()) {
            return response()->json(['error' => 'Deze uitnodiging is niet meer geldig.'], 422);
        }
        if ($invite->isExpired()) {
            $invite->update(['status' => 'revoked']);
            return response()->json(['error' => 'Deze uitnodiging is verlopen.'], 422);
        }

        $this->service->accept($invite, $user);

        return response()->json([
            'message'       => 'Uitnodiging geaccepteerd.',
            'resource_type' => $invite->resourceType(),
            'resource_id'   => $invite->invitable_id,
        ]);
    }

    /**
     * Decline an invitation addressed to the authenticated user.
     * POST /api/v1/invitations/token/{token}/decline
     */
    public function decline($token)
    {
        $invite = Invitation::where('token', $token)->firstOrFail();
        $user   = Auth::user();

        if (! $this->addressedTo($invite, $user)) {
            abort(403, 'Deze uitnodiging is niet voor jou bestemd.');
        }
        if ($invite->isPending()) {
            $invite->update(['status' => 'declined']);
        }

        return response()->json(['message' => 'Uitnodiging afgewezen.']);
    }

    /**
     * Invitations waiting for the authenticated user (Hub inbox).
     * GET /api/v1/invitations/incoming
     */
    public function incoming()
    {
        $user = Auth::user();

        $invites = Invitation::pending()
            ->where(function ($q) use ($user) {
                $q->where('email', mb_strtolower($user->email))
                    ->orWhere('invited_user_id', $user->id);
            })
            ->with(['invitable', 'inviter:id,first_name,last_name'])
            ->latest()
            ->get()
            ->map(fn ($i) => $this->present($i));

        return response()->json(['invitations' => $invites]);
    }

    /**
     * Invitations the authenticated user has sent out.
     * GET /api/v1/invitations/outgoing
     */
    public function outgoing()
    {
        $invites = Invitation::where('invited_by', Auth::id())
            ->with(['invitable', 'inviter:id,first_name,last_name'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn ($i) => $this->present($i));

        return response()->json(['invitations' => $invites]);
    }

    /**
     * Revoke an invitation the authenticated user sent.
     * DELETE /api/v1/invitations/{id}
     */
    public function revoke($id)
    {
        $invite = Invitation::findOrFail($id);

        if ((int) $invite->invited_by !== Auth::id()) {
            abort(403, 'Je kunt alleen je eigen uitnodigingen intrekken.');
        }

        $invite->update(['status' => 'revoked']);

        return response()->json(['message' => 'Uitnodiging ingetrokken.']);
    }

    /**
     * A merged activity timeline for the Hub: invites received, invites sent
     * (and whether they were accepted) and collaborations the user joined.
     * GET /api/v1/activity/timeline
     */
    public function timeline()
    {
        $user   = Auth::id();
        $email  = mb_strtolower(Auth::user()->email);
        $events = collect();

        // Invitations I received.
        Invitation::where(function ($q) use ($user, $email) {
            $q->where('invited_user_id', $user)->orWhere('email', $email);
        })
            ->with(['invitable', 'inviter:id,first_name,last_name'])
            ->latest()->limit(40)->get()
            ->each(function ($i) use (&$events) {
                $accepted = $i->status === 'accepted';
                $events->push([
                    'type'    => $accepted ? 'invite_accepted' : 'invite_received',
                    'title'   => $accepted ? 'Uitnodiging geaccepteerd' : 'Uitnodiging ontvangen',
                    'subtitle' => ($i->inviter?->full_name ?? 'Iemand') . ' · ' . $i->resourceLabel() . ' "' . $i->resourceTitle() . '"',
                    'status'  => $i->status,
                    'resource_type' => $i->resourceType(),
                    'resource_id'   => $i->invitable_id,
                    'token'   => $i->token,
                    'at'      => ($i->accepted_at ?? $i->created_at)?->toIso8601String(),
                ]);
            });

        // Invitations I sent.
        Invitation::where('invited_by', $user)
            ->with(['invitable'])
            ->latest()->limit(40)->get()
            ->each(function ($i) use (&$events) {
                $events->push([
                    'type'    => 'invite_sent',
                    'title'   => $i->status === 'accepted' ? 'Jouw uitnodiging is geaccepteerd' : 'Uitnodiging verstuurd',
                    'subtitle' => $i->email . ' · ' . $i->resourceLabel() . ' "' . $i->resourceTitle() . '"',
                    'status'  => $i->status,
                    'resource_type' => $i->resourceType(),
                    'resource_id'   => $i->invitable_id,
                    'at'      => ($i->status === 'accepted' ? ($i->accepted_at ?? $i->updated_at) : $i->created_at)?->toIso8601String(),
                ]);
            });

        // Team memberships involving me: teams I joined, or members added to
        // a team I own.
        TeamMember::with(['team:id,name,owner_id', 'user:id,first_name,last_name'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user)
                    ->orWhereHas('team', fn ($t) => $t->where('owner_id', $user));
            })
            ->latest()->limit(40)->get()
            ->each(function ($m) use (&$events, $user) {
                $teamName = $m->team?->name ?? 'een team';
                $iAmOwner = $m->team && (int) $m->team->owner_id === (int) $user;
                $isMe     = (int) $m->user_id === (int) $user;

                if ($isMe && ! $iAmOwner) {
                    $title = 'Toegevoegd aan team';
                    $sub   = 'Team "' . $teamName . '"';
                } elseif ($iAmOwner && ! $isMe) {
                    $title = 'Teamlid toegevoegd';
                    $sub   = $m->displayName() . ' · team "' . $teamName . '"';
                } else {
                    return; // me in my own team — not noteworthy
                }

                $events->push([
                    'type'          => 'team_member',
                    'title'         => $title,
                    'subtitle'      => $sub,
                    'resource_type' => 'team',
                    'resource_id'   => $m->team_id,
                    'at'            => $m->created_at?->toIso8601String(),
                ]);
            });

        // Chats I'm part of (direct conversations) — surfaced as activity.
        Conversation::where('type', 'direct')
            ->whereHas('participants', fn ($q) => $q->where('users.id', $user))
            ->with(['participants:id,first_name,last_name,email'])
            ->latest()->limit(20)->get()
            ->each(function ($c) use (&$events, $user) {
                $other = $c->participants->firstWhere('id', '!=', $user);
                // Val terug op het e-mailadres wanneer de deelnemer (nog) geen
                // naam heeft ingevuld, zodat "Chat met" nooit leeg/anoniem is.
                $otherName = $other?->full_name ?: ($other?->email ?? 'een deelnemer');
                $events->push([
                    'type'          => 'chat_started',
                    'title'         => 'Nieuw gesprek',
                    'subtitle'      => 'Chat met ' . $otherName,
                    'resource_type' => 'chat',
                    'resource_id'   => $c->id,
                    'at'            => $c->created_at?->toIso8601String(),
                ]);
            });

        // Chat invites I sent that have now been accepted (the invitee
        // registered an account). Link to the direct conversation that was
        // opened on their behalf so the inviter can jump straight in.
        ChatInvite::where('inviter_id', $user)
            ->where('status', 'accepted')
            ->with(['acceptedUser:id,first_name,last_name,email'])
            ->latest('accepted_at')->limit(20)->get()
            ->each(function ($ci) use (&$events, $user) {
                $name = $ci->acceptedUser?->full_name
                    ?: ($ci->acceptedUser?->email ?? $ci->email);

                // Find the direct conversation we opened with this person.
                $conversationId = null;
                if ($ci->accepted_user_id) {
                    $conversationId = Conversation::where('type', 'direct')
                        ->whereHas('participants', fn ($q) => $q->where('users.id', $user))
                        ->whereHas('participants', fn ($q) => $q->where('users.id', $ci->accepted_user_id))
                        ->value('id');
                }

                $events->push([
                    'type'          => 'chat_invite_accepted',
                    'title'         => 'Chatuitnodiging geaccepteerd',
                    'subtitle'      => $name . ' heeft een account aangemaakt',
                    'status'        => 'accepted',
                    'resource_type' => $conversationId ? 'chat' : null,
                    'resource_id'   => $conversationId,
                    'at'            => ($ci->accepted_at ?? $ci->updated_at)?->toIso8601String(),
                ]);
            });

        // Chat requests I sent that were accepted by the other (existing) user.
        ChatRequest::where('requester_id', $user)
            ->where('status', 'accepted')
            ->with(['recipient:id,first_name,last_name,email'])
            ->latest('responded_at')->limit(20)->get()
            ->each(function ($r) use (&$events, $user) {
                $name = $r->recipient?->full_name ?: ($r->recipient?->email ?? 'Iemand');

                $conversationId = DB::table('conversation_user as cu1')
                    ->join('conversation_user as cu2', 'cu1.conversation_id', '=', 'cu2.conversation_id')
                    ->join('conversations as c', 'c.id', '=', 'cu1.conversation_id')
                    ->where('c.type', 'direct')
                    ->where('cu1.user_id', $user)
                    ->where('cu2.user_id', $r->recipient_id)
                    ->value('cu1.conversation_id');

                $events->push([
                    'type'          => 'chat_invite_accepted',
                    'title'         => 'Chatverzoek geaccepteerd',
                    'subtitle'      => $name . ' accepteerde je chatverzoek',
                    'status'        => 'accepted',
                    'resource_type' => $conversationId ? 'chat' : null,
                    'resource_id'   => $conversationId,
                    'at'            => ($r->responded_at ?? $r->updated_at)?->toIso8601String(),
                ]);
            });

        $events = $events
            ->filter(fn ($e) => ! empty($e['at']))
            ->sortByDesc('at')
            ->values()
            ->take(30);

        return response()->json(['events' => $events]);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    protected function addressedTo(Invitation $invite, User $user): bool
    {
        return mb_strtolower($user->email) === $invite->email
            || (int) $invite->invited_user_id === (int) $user->id;
    }

    protected function present(Invitation $i): array
    {
        return [
            'id'             => $i->id,
            'token'          => $i->token,
            'email'          => $i->email,
            'role'           => $i->role,
            'status'         => $i->status,
            'resource_type'  => $i->resourceType(),
            'resource_label' => $i->resourceLabel(),
            'resource_title' => $i->resourceTitle(),
            'resource_id'    => $i->invitable_id,
            'invited_by'     => $i->inviter?->full_name,
            'created_at'     => $i->created_at?->toIso8601String(),
            'accepted_at'    => $i->accepted_at?->toIso8601String(),
            'expires_at'     => $i->expires_at?->toIso8601String(),
        ];
    }
}
