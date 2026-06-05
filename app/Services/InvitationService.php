<?php

namespace App\Services;

use App\Events\MapCollaboratorAdded;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Map;
use App\Models\MapCollaborator;
use App\Models\Mission;
use App\Models\MissionCollaborator;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Central place for the "invite by e-mail" + "accept invitation" flow that is
 * shared between missions and maps. Email invites (existing user OR brand-new
 * recipient) all go through the polymorphic `invitations` table so the Hub
 * inbox has a single source of truth.
 */
class InvitationService
{
    /**
     * Set of user IDs the given user has an *accepted* collaboration with
     * (either direction, across missions and maps). These are the only people
     * shown in user-search; everyone else must be invited by e-mail.
     *
     * @return int[]
     */
    public static function knownContactIds(int $userId): array
    {
        $ids = collect();

        // ── Missions ────────────────────────────────────────────────
        $ownedMissionIds  = Mission::where('owner_id', $userId)->pluck('id');
        $memberMissionIds = MissionCollaborator::where('user_id', $userId)
            ->where('status', 'accepted')->pluck('mission_id');

        $ids = $ids
            // accepted collaborators on missions I own
            ->merge(MissionCollaborator::whereIn('mission_id', $ownedMissionIds)
                ->where('status', 'accepted')->pluck('user_id'))
            // owners of missions I'm an accepted member of
            ->merge(Mission::whereIn('id', $memberMissionIds)->pluck('owner_id'))
            // co-members on missions I'm an accepted member of
            ->merge(MissionCollaborator::whereIn('mission_id', $memberMissionIds)
                ->where('status', 'accepted')->pluck('user_id'));

        // ── Maps ────────────────────────────────────────────────────
        $ownedMapIds  = Map::where('owner_id', $userId)->pluck('id');
        $memberMapIds = MapCollaborator::where('user_id', $userId)
            ->where('status', 'accepted')->pluck('map_id');

        $ids = $ids
            ->merge(MapCollaborator::whereIn('map_id', $ownedMapIds)
                ->where('status', 'accepted')->pluck('user_id'))
            ->merge(Map::whereIn('id', $memberMapIds)->pluck('owner_id'))
            ->merge(MapCollaborator::whereIn('map_id', $memberMapIds)
                ->where('status', 'accepted')->pluck('user_id'));

        return $ids
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === $userId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Create an e-mail invitation for a mission or map and send the e-mail.
     *
     * @param  Mission|Map  $invitable
     * @return array{ok:bool,status?:int,error?:string,invitation?:Invitation,isNewUser?:bool,email_sent?:bool}
     */
    public function createInvite($invitable, string $email, ?string $role, User $inviter): array
    {
        $email = mb_strtolower(trim($email));
        $role  = in_array($role, ['viewer', 'editor', 'admin'], true) ? $role : 'viewer';

        $existingUser = User::where('email', $email)->first();
        $ownerId      = (int) $invitable->owner_id;

        if ($existingUser && (int) $existingUser->id === $ownerId) {
            return ['ok' => false, 'status' => 422, 'error' => 'De eigenaar kan niet worden uitgenodigd.'];
        }

        // Already an active/pending collaborator?
        if ($existingUser && $this->collaboratorQuery($invitable)->where('user_id', $existingUser->id)->exists()) {
            return ['ok' => false, 'status' => 422, 'error' => 'Deze gebruiker is al toegevoegd aan deze ' . $this->label($invitable) . '.'];
        }

        // Already an open invitation?
        $open = Invitation::where('email', $email)
            ->where('invitable_type', get_class($invitable))
            ->where('invitable_id', $invitable->id)
            ->where('status', 'pending')
            ->first();
        if ($open) {
            return ['ok' => false, 'status' => 422, 'error' => 'Er staat al een uitnodiging open voor dit e-mailadres.'];
        }

        $invite = Invitation::create([
            'email'           => $email,
            'invited_user_id' => $existingUser?->id,
            'invitable_type'  => get_class($invitable),
            'invitable_id'    => $invitable->id,
            'role'            => $role,
            'invited_by'      => $inviter->id,
            'token'           => Invitation::generateToken(),
            'status'          => 'pending',
            'expires_at'      => now()->addDays(30),
        ]);

        // Avoid extra queries inside the mailable.
        $invite->setRelation('invitable', $invitable);
        $invite->setRelation('inviter', $inviter);

        $emailSent = $this->sendInviteEmail($invite, ! $existingUser);

        return [
            'ok'         => true,
            'invitation' => $invite,
            'isNewUser'  => ! $existingUser,
            'email_sent' => $emailSent,
        ];
    }

    /**
     * Accept a pending invitation for the given (authenticated) user, creating
     * the appropriate collaborator row. View-only accounts are forced to the
     * 'viewer' role regardless of what was invited.
     */
    public function accept(Invitation $invite, User $user): void
    {
        if (! $invite->isPending()) {
            return;
        }

        $role     = $user->isViewOnly() ? 'viewer' : $invite->role;
        $resource = $invite->invitable;

        if (! $resource) {
            $invite->update(['status' => 'revoked']);
            return;
        }

        if ($resource instanceof Mission) {
            if (! $resource->isOwnedBy($user->id)) {
                $c = MissionCollaborator::where('mission_id', $resource->id)
                    ->where('user_id', $user->id)->first();
                if (! $c) {
                    MissionCollaborator::create([
                        'mission_id'  => $resource->id,
                        'user_id'     => $user->id,
                        'added_by'    => $invite->invited_by,
                        'role'        => $role,
                        'status'      => 'accepted',
                        'invited_at'  => $invite->created_at,
                        'accepted_at' => now(),
                    ]);
                } elseif ($c->status !== 'accepted') {
                    $c->update(['status' => 'accepted', 'accepted_at' => now()]);
                }
            }
        } elseif ($resource instanceof Map) {
            if ((int) $resource->owner_id !== (int) $user->id) {
                $c = MapCollaborator::where('map_id', $resource->id)
                    ->where('user_id', $user->id)->first();
                if (! $c) {
                    $c = MapCollaborator::create([
                        'map_id'      => $resource->id,
                        'user_id'     => $user->id,
                        'added_by'    => $invite->invited_by,
                        'role'        => $role,
                        'status'      => 'accepted',
                        'invited_at'  => $invite->created_at,
                        'accepted_at' => now(),
                    ]);
                    try {
                        broadcast(new MapCollaboratorAdded($c))->toOthers();
                    } catch (\Throwable $e) {
                        Log::warning('Kon MapCollaboratorAdded niet broadcasten', ['error' => $e->getMessage()]);
                    }
                } elseif ($c->status !== 'accepted') {
                    $c->update(['status' => 'accepted', 'accepted_at' => now()]);
                }
            }
        }

        $invite->update([
            'status'      => 'accepted',
            'accepted_by' => $user->id,
            'accepted_at' => now(),
        ]);
    }

    /**
     * Called right after registration: turns any pending invitations addressed
     * to the new user's e-mail into accepted (view-only) collaborations, and
     * marks the account as view-only (free) until they take out a subscription.
     *
     * @return int number of invitations converted
     */
    public function convertForNewUser(User $user): int
    {
        $pending = Invitation::forEmail($user->email)->pending()->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        // Bootstrapped-by-invite account → free / view-only until paid.
        if (! $user->view_only) {
            $user->forceFill(['view_only' => true])->save();
        }

        foreach ($pending as $invite) {
            $invite->update(['invited_user_id' => $user->id]);
            $this->accept($invite, $user);
        }

        return $pending->count();
    }

    // ── Internals ───────────────────────────────────────────────────

    protected function sendInviteEmail(Invitation $invite, bool $isNewUser): bool
    {
        try {
            // Synchronous send: QUEUE_CONNECTION=database has no worker in dev,
            // so queueing would leave the e-mail un-sent.
            Mail::to($invite->email)->send(new InvitationMail($invite, $isNewUser));
            return true;
        } catch (\Throwable $e) {
            Log::error('Uitnodigingse-mail kon niet worden verzonden', [
                'invitation' => $invite->id,
                'email'      => $invite->email,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function collaboratorQuery($invitable)
    {
        return $invitable instanceof Mission
            ? MissionCollaborator::where('mission_id', $invitable->id)
            : MapCollaborator::where('map_id', $invitable->id);
    }

    protected function label($invitable): string
    {
        return $invitable instanceof Mission ? 'missie' : 'kaart';
    }
}
