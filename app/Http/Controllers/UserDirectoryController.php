<?php

namespace App\Http\Controllers;

use App\Services\CollaboratorDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /api/v1/users/directory?q=
 *
 * Zoekt mensen om aan een missie toe te voegen: eerdere samenwerkers, leden van de
 * eigen Connect-organisatie en een exacte gebruikersnaam. Wie je ziet en of je er
 * een e-mailadres bij krijgt bepaalt CollaboratorDirectory.
 */
class UserDirectoryController extends Controller
{
    public function search(Request $request, CollaboratorDirectory $directory): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        return response()->json([
            'users' => mb_strlen($query) > 100 ? [] : $directory->search(Auth::user(), $query),
        ]);
    }
}
