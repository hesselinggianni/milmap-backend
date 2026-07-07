<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserUpload;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    use AuthorizesRequests;
    
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index()
    {
        // Admin-only: never expose the full user directory to regular accounts.
        $this->authorize('viewAny', User::class);

        return response()->json($this->userService->getAllUsers(), 200);
    }



    public function store(Request $request)
    {
     
        $this->authorize('create', User::class);

        $request->validate([          
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'language' => 'string|max:2',
        ]);

        $user = $this->userService->createUser($request->only([ 'email', 'password','language']));

        return response()->json($user, 201);
    }



    public function show($id)
{
    try {
        // Fetch user data by ID
        $user = $this->userService->getUserById($id);

        // Self-or-admin: a user may only read their own full record.
        $this->authorize('view', $user);

        return response()->json($user, 200);
    } catch (ModelNotFoundException $e) {
        return response()->json(['message' => 'User not found'], 404);
    }
}


    public function update(Request $request, $id)
    {
        try {
            $user = $this->userService->getUserById($id);

            // Self-or-admin: blocks the account-takeover IDOR (a logged-in user
            // could otherwise rewrite anyone's email/password by id).
            $this->authorize('update', $user);

            $request->validate([
                'email' => 'string|email|max:255|unique:users,email,' . $id,
                'password' => 'string|min:8',
                'language' => 'string|max:2',
                'settings' => 'array',


            ]);

            // Defense-in-depth: a non-admin editing their own record may only
            // change low-risk preferences through this generic endpoint. Email
            // and password changes must go through the dedicated self-service
            // endpoints (/user/password verifies the current password first),
            // so a hijacked session can't silently swap credentials here.
            $isAdmin = (bool) (Auth::user()->is_admin ?? false);
            $allowed = $isAdmin
                ? ['email', 'password', 'language', 'settings']
                : ['language', 'settings'];

            $updatedUser = $this->userService->updateUser($id, $request->only($allowed));
            
            return response()->json(
                [
                    'success' => true,
                    'message' => 'User updated successfully',
                    'data' => $updatedUser
                ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'User not found'], 404);
        }
    }




    public function destroy($id)
    {
        try {
            $user = $this->userService->getUserById($id);

            // Self-or-admin: a user may delete only their own account.
            $this->authorize('delete', $user);

            $this->userService->deleteUser($id);

            return response()->json(['message' => 'User deleted successfully'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'User not found'], 404);
        }
    }

    public function me()
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Verificatie- én premium-/proefstatus meesturen zodat de frontend de
        // wall kan tonen en de premium-UI op slot kan zetten zonder losse call.
        return response()->json(
            array_merge($user->toArray(), [
                'verification' => $user->verificationState(),
                'premium'      => $user->premiumState(),
            ]),
            200
        );
    }

    /**
     * PUT /api/v1/user/profile
     * Werkt voornaam, achternaam en taal van de ingelogde gebruiker bij.
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name'  => ['nullable', 'string', 'max:80'],
            'language'   => ['nullable', 'string', 'max:5'],
        ]);

        $updated = $this->userService->updateUser($user->id, $validated);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated',
            'data'    => $updated,
        ], 200);
    }

    /**
     * POST /api/v1/user/avatar
     * Uploadt (of vervangt) de profielfoto van de ingelogde gebruiker. Slaat
     * de afbeelding op de 'public' disk op onder avatars/, ruimt de oude foto
     * op en boekt het verbruik in het opslag-grootboek (user_uploads).
     */
    public function uploadAvatar(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $request->validate([
            // ~5 MB; gangbare web-formaten. HEIC laten we bewust weg — niet
            // alle browsers tonen die, dus de client converteert eerst.
            'avatar' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
        ]);

        $old = $user->avatar_path;

        // Bewaar onder een per-gebruiker map zodat opruimen simpel blijft.
        $path = $request->file('avatar')->store("avatars/{$user->id}", 'public');

        $user->avatar_path = $path;
        $user->save();

        // Oude foto verwijderen — voorkomt wees-bestanden bij elke wissel.
        if ($old && $old !== $path) {
            try {
                Storage::disk('public')->delete($old);
            } catch (\Throwable $e) {
                // Best-effort opruimen; nooit de upload laten klappen.
            }
        }

        // Grootboek bijwerken: tel de nieuwe foto, ruim oude avatar-rijen op.
        try {
            UserUpload::where('user_id', $user->id)->where('kind', 'avatar')->delete();
            $file = $request->file('avatar');
            UserUpload::record(
                $user->id,
                $path,
                (int) $file->getSize(),
                $file->getMimeType(),
                'avatar',
                'public'
            );
        } catch (\Throwable $e) {
            // Boekhouding mag een geslaagde upload niet alsnog laten falen.
        }

        return response()->json([
            'success'    => true,
            'message'    => 'Avatar updated',
            'avatar_url' => $user->avatar_url,
            'data'       => $user->fresh(),
        ], 200);
    }

    /**
     * DELETE /api/v1/user/avatar
     * Verwijdert de profielfoto van de ingelogde gebruiker (terug naar
     * initialen). Het fysieke bestand wordt direct van de disk gehaald.
     */
    public function deleteAvatar()
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $old = $user->avatar_path;
        $user->avatar_path = null;
        $user->save();

        if ($old) {
            try {
                Storage::disk('public')->delete($old);
            } catch (\Throwable $e) {
                // best-effort
            }
            try {
                UserUpload::where('user_id', $user->id)->where('kind', 'avatar')->delete();
            } catch (\Throwable $e) {
                // best-effort
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Avatar removed',
            'data'    => $user->fresh(),
        ], 200);
    }

    /**
     * PUT /api/v1/user/settings
     * Werkt de persoonlijke voorkeuren (settings-JSON) van de ingelogde
     * gebruiker bij. Alleen bekende sleutels worden gevalideerd en
     * samengevoegd, zodat andere settings behouden blijven.
     */
    public function updateSettings(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            // Privacy: "laatst online" zichtbaar voor anderen (opt-out).
            'show_last_seen' => ['sometimes', 'boolean'],
            // E-mailnotificaties bij storingen of gepland onderhoud (opt-in,
            // standaard uit). Wordt uitgelezen door `status:notify`.
            'notify_status_emails' => ['sometimes', 'boolean'],
        ]);

        $settings = $user->settings ?? [];
        foreach ($validated as $key => $value) {
            $settings[$key] = $value;
        }

        $user->settings = $settings;
        $user->save();

        return response()->json([
            'success'  => true,
            'message'  => 'Settings updated',
            'settings' => $settings,
            'data'     => $user,
        ], 200);
    }

    /**
     * PUT /api/v1/user/password
     * Wijzigt het wachtwoord van de ingelogde gebruiker na verificatie
     * van het huidige wachtwoord.
     */
    public function changePassword(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!\Illuminate\Support\Facades\Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Huidig wachtwoord is onjuist.',
                'errors'  => ['current_password' => ['Huidig wachtwoord is onjuist.']],
            ], 422);
        }

        $this->userService->updateUser($user->id, ['password' => $validated['password']]);

        return response()->json([
            'success' => true,
            'message' => 'Password updated',
        ], 200);
    }
}
