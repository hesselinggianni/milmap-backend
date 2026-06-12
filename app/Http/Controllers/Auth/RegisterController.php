<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\NewUserRegistered;
use App\Models\ChatInvite;
use App\Models\Conversation;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Log;

class RegisterController extends Controller
{
    public function store(Request $request)
    {

           // Check if the user already exists
           $existingUser = User::where('email', $request->email)->first();

           if ($existingUser) {
               throw ValidationException::withMessages([
                   'email' => ['Deze gebruiker is al geregistreerd.'],
               ]);
           }

           
        // Validate input data
        $validator = Validator::make($request->all(), [       
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        // Share-attributie: als de registratie via een uitgedeelde link binnenkomt
        // (frontend stuurt `utm_source` mee als share_uuid van de ambassadeur),
        // koppelen we 'm hier. Onbekende of eigen-uuid wordt stil overgeslagen.
        $referredById = null;
        $utm = $request->input('utm_source');
        if (is_string($utm) && preg_match('/^[0-9a-f-]{36}$/i', $utm)) {
            $referrer = User::where('share_uuid', strtolower($utm))->first(['id']);
            if ($referrer) $referredById = $referrer->id;
        }

        // Create the user
        $user = User::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'referred_by_id' => $referredById,
        ]);

        // If this e-mail was invited to any mission/map, accept those invitations
        // now. Such accounts become free / view-only until they upgrade.
        $invitesAccepted = app(InvitationService::class)->convertForNewUser($user);
        $user->refresh();

        // If anyone invited this e-mail to chat, mark those invites accepted and
        // open a direct conversation so the inviter sees a "chat invite accepted"
        // event on their Hub timeline (and can jump straight into the chat).
        $this->acceptChatInvites($user);

        Mail::to('hesselinggianni@gmail.com')
        ->send(new NewUserRegistered($user));

        // Generate a Sanctum token scoped to the regular-app 'user' ability so
        // it can never satisfy tokenCan('admin') (admin routes require a token
        // minted through the admin login flow).
        $token = $user->createToken('API Token', ['user'])->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully.',
            'user' => $user,
            'token' => $token,
            'invites_accepted' => $invitesAccepted,
        ], 201);
    }

    /**
     * Mark every pending chat invite for this user's e-mail as accepted, and
     * make sure a direct conversation exists between each inviter and the new
     * user. Failures are logged but never block registration.
     */
    protected function acceptChatInvites(User $user): void
    {
        $email = mb_strtolower($user->email);

        $invites = ChatInvite::where('email', $email)
            ->where('status', 'pending')
            ->get();

        foreach ($invites as $invite) {
            try {
                $this->ensureDirectConversation((int) $invite->inviter_id, (int) $user->id);

                $invite->update([
                    'status'           => 'accepted',
                    'accepted_user_id' => $user->id,
                    'accepted_at'      => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[chat] accept invite on register failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Find-or-create a 1-on-1 conversation between two users.
     */
    protected function ensureDirectConversation(int $a, int $b): void
    {
        if ($a === $b) {
            return;
        }

        $exists = Conversation::where('type', 'direct')
            ->whereHas('participants', fn ($q) => $q->where('users.id', $a))
            ->whereHas('participants', fn ($q) => $q->where('users.id', $b))
            ->exists();

        if ($exists) {
            return;
        }

        $conversation = Conversation::create([
            'type'       => 'direct',
            'created_by' => $a,
        ]);
        $conversation->participants()->attach([$a, $b]);
    }
}
