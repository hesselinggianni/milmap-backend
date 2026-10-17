<?php

namespace App\Http\Controllers;

use App\Models\KakoFolder;
use App\Models\KakoFolderItem;
use App\Models\RouteAssignment;
use App\Models\RouteAssignmentCollaborator;
use Illuminate\Http\Request;

/** Persoonlijke mappen voor het KAKO-overzicht. */
class KakoFolderController extends Controller
{
    public function index()
    {
        return response()->json(
            KakoFolder::where('user_id', auth()->id())->orderBy('name')->get(['id', 'name'])
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        abort_if(KakoFolder::where('user_id', auth()->id())->count() >= 100, 422, 'Maximaal 100 mappen');
        $folder = KakoFolder::create(['user_id' => auth()->id(), 'name' => trim($data['name'])]);

        return response()->json(['id' => $folder->id, 'name' => $folder->name], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $folder = KakoFolder::where('user_id', auth()->id())->findOrFail($id);
        $folder->update(['name' => trim($data['name'])]);

        return response()->json(['id' => $folder->id, 'name' => $folder->name]);
    }

    /** Verwijdert alleen de map: de trainingen zelf blijven bestaan (komen terug onder "Zonder map"). */
    public function destroy(int $id)
    {
        KakoFolder::where('user_id', auth()->id())->findOrFail($id)->delete();

        return response()->json(['message' => 'Map verwijderd']);
    }

    /** PUT /route-assignments/{id}/folder { folder_id: int|null } */
    public function assign(Request $request, string $id)
    {
        $data = $request->validate(['folder_id' => ['nullable', 'integer']]);
        $assignment = RouteAssignment::findOrFail($id);
        $userId = auth()->id();

        // Alleen trainingen waar je (via eigenaarschap, uitnodiging of kaart) bij hoort.
        $mine = (int) $assignment->owner_id === (int) $userId
            || RouteAssignmentCollaborator::where('route_assignment_id', $assignment->id)->where('user_id', $userId)->where('status', 'accepted')->exists()
            || app(\App\Services\MapAccess::class)->accessibleQuery(auth()->user())->where('id', $assignment->map_id)->exists();
        abort_unless($mine, 403);

        if (empty($data['folder_id'])) {
            KakoFolderItem::where('user_id', $userId)->where('route_assignment_id', $assignment->id)->delete();
            return response()->json(['folder_id' => null]);
        }

        $folder = KakoFolder::where('user_id', $userId)->findOrFail($data['folder_id']);
        KakoFolderItem::updateOrCreate(
            ['user_id' => $userId, 'route_assignment_id' => $assignment->id],
            ['folder_id' => $folder->id]
        );

        return response()->json(['folder_id' => $folder->id]);
    }
}
