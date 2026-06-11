<?php

namespace App\Http\Controllers;

use App\Models\MissionTaskTemplate;
use App\Models\MissionTaskTemplateItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * CRUD voor herbruikbare taken-templates die de gebruiker in /menu beheert
 * en bij het aanmaken van een missie kan kiezen om de items als losse taken
 * uit te rollen.
 *
 * Eigendomsmodel: per gebruiker (owner_id), niet gedeeld. Hou het simpel.
 * Templates delen tussen accounts kan later via "kopiëren" of een aparte
 * sharing-laag — niet in v1.
 */
class MissionTaskTemplateController extends Controller
{
    public function index()
    {
        $uid = Auth::id();
        $templates = MissionTaskTemplate::with('items')
            ->where('owner_id', $uid)
            ->orderBy('name')
            ->get();

        return response()->json([
            'templates' => $templates->map(fn ($t) => $this->present($t)),
        ]);
    }

    public function store(Request $request)
    {
        $uid = Auth::id();
        $data = $request->validate([
            'name'            => 'required|string|max:120',
            'description'     => 'nullable|string|max:500',
            'items'           => 'nullable|array',
            'items.*.title'   => 'required|string|max:255',
            'items.*.description' => 'nullable|string',
        ]);

        $template = DB::transaction(function () use ($uid, $data) {
            $template = MissionTaskTemplate::create([
                'owner_id'    => $uid,
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            foreach ($data['items'] ?? [] as $i => $item) {
                MissionTaskTemplateItem::create([
                    'template_id' => $template->id,
                    'title'       => $item['title'],
                    'description' => $item['description'] ?? null,
                    'order_index' => $i,
                ]);
            }
            return $template;
        });

        return response()->json(['template' => $this->present($template->fresh('items'))], 201);
    }

    public function show(string $id)
    {
        $uid = Auth::id();
        $template = MissionTaskTemplate::with('items')
            ->where('owner_id', $uid)
            ->where('id', $id)
            ->firstOrFail();
        return response()->json(['template' => $this->present($template)]);
    }

    public function update(Request $request, string $id)
    {
        $uid = Auth::id();
        $template = MissionTaskTemplate::where('owner_id', $uid)->findOrFail($id);

        $data = $request->validate([
            'name'            => 'sometimes|required|string|max:120',
            'description'     => 'nullable|string|max:500',
            // Items wordt volledig vervangen (eenvoudiger dan diff'en).
            // Frontend stuurt altijd de huidige lijst mee bij save.
            'items'           => 'nullable|array',
            'items.*.title'   => 'required|string|max:255',
            'items.*.description' => 'nullable|string',
        ]);

        DB::transaction(function () use ($template, $data) {
            $template->fill(collect($data)->only(['name', 'description'])->all())->save();
            if (array_key_exists('items', $data)) {
                MissionTaskTemplateItem::where('template_id', $template->id)->delete();
                foreach ($data['items'] as $i => $item) {
                    MissionTaskTemplateItem::create([
                        'template_id' => $template->id,
                        'title'       => $item['title'],
                        'description' => $item['description'] ?? null,
                        'order_index' => $i,
                    ]);
                }
            }
        });

        return response()->json(['template' => $this->present($template->fresh('items'))]);
    }

    public function destroy(string $id)
    {
        $uid = Auth::id();
        $template = MissionTaskTemplate::where('owner_id', $uid)->findOrFail($id);
        $template->delete();
        return response()->json(['ok' => true]);
    }

    protected function present(MissionTaskTemplate $t): array
    {
        return [
            'id'          => $t->id,
            'name'        => $t->name,
            'description' => $t->description,
            'item_count'  => $t->items->count(),
            'items'       => $t->items->map(fn ($i) => [
                'id'          => $i->id,
                'title'       => $i->title,
                'description' => $i->description,
                'order_index' => $i->order_index,
            ])->values(),
            'updated_at'  => optional($t->updated_at)->toIso8601String(),
        ];
    }
}
