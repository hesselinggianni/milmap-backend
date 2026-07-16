<?php

namespace App\Http\Controllers;

use App\Models\TaskLabel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Beheer van de taak-labels (categorieën + platforms) voor de taken-omgeving.
 * Alleen admin (Sanctum + admin.auth).
 */
class TaskLabelController extends Controller
{
    public function index()
    {
        $labels = TaskLabel::orderBy('kind')->orderBy('position')->orderBy('name')->get();

        return response()->json([
            'labels' => $labels->map(fn ($l) => $l->toApiArray())->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kind'  => ['required', Rule::in(TaskLabel::KINDS)],
            'name'  => 'required|string|max:80',
            'color' => 'nullable|string|max:20',
        ]);

        // Idempotent: bestaat het label (kind+naam) al, geef dat terug.
        $label = TaskLabel::firstOrCreate(
            ['kind' => $data['kind'], 'name' => $data['name']],
            ['color' => $data['color'] ?? null]
        );

        return response()->json(['label' => $label->toApiArray()], 201);
    }

    public function update(Request $request, int $id)
    {
        $label = TaskLabel::findOrFail($id);
        $data = $request->validate([
            'name'  => 'sometimes|string|max:80',
            'color' => 'sometimes|nullable|string|max:20',
        ]);
        $label->fill($data)->save();

        return response()->json(['label' => $label->toApiArray()]);
    }

    public function destroy(int $id)
    {
        TaskLabel::where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }
}
