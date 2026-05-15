<?php
namespace App\Http\Controllers;

use App\Models\SearchHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SearchHistoryController extends Controller
{
    public function index(Request $request)
{
    $query = SearchHistory::where('user_id', Auth::id());

    if ($request->has('map_id')) {
        $query->where('map_id', $request->map_id);
    }

    return response()->json(
        $query->latest('created_at')->get()
    );
}

public function store(Request $request)
{
    $request->validate([
        'title' => 'required|string',
        'subtitle' => 'nullable|string',
        'lat' => 'required|numeric',
        'lon' => 'required|numeric',
        'map_id' => 'required|uuid',
    ]);

    // 🔥 bestaande entry verwijderen
    SearchHistory::where('user_id', Auth::id())
        ->where('map_id', $request->map_id)
        ->where('lat', $request->lat)
        ->where('lon', $request->lon)
        ->delete();

    // 🔥 nieuwe entry maken
    $history = SearchHistory::create([
        'user_id' => Auth::id(),
        'map_id' => $request->map_id,
        'title' => $request->title,
        'subtitle' => $request->subtitle,
        'lat' => $request->lat,
        'lon' => $request->lon,
        'created_at' => now(),
    ]);

    return response()->json($history, 201);
}

    public function destroy($id)
    {
        $history = SearchHistory::where('id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $history->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function clear()
    {
        SearchHistory::where('user_id', Auth::id())->delete();

        return response()->json(['message' => 'Cleared']);
    }
}