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
        // Authorize the 'viewAny' policy method
        // $this->authorize('viewAny', User::class);

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

        // // Authorize the 'view' policy method
        // $this->authorize('view', $user);

      
        return response()->json($user, 200);
    } catch (ModelNotFoundException $e) {
        return response()->json(['message' => 'User not found'], 404);
    }
}


    public function update(Request $request, $id)
    {
        try {
            $user = $this->userService->getUserById($id);

            // Authorize the 'update' policy method
            // $this->authorize('update', $user);

            $request->validate([               
                'email' => 'string|email|max:255|unique:users,email,' . $id,
                'password' => 'string|min:8',
                'language' => 'string|max:2',
                'settings' => 'array',
              

            ]);

            $updatedUser = $this->userService->updateUser($id, $request->only([
               
                'email',
                'password',
                'language',
                'settings'               

            ]));
            
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

            // Authorize the 'delete' policy method
            // $this->authorize('delete', $user);

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
