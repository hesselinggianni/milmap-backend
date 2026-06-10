<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;

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

        return response()->json($user, 200);
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
