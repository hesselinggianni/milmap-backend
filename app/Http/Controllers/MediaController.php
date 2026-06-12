<?php

namespace App\Http\Controllers;

use App\Models\UserUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Media-beheer voor de "Opslag"-rij in de menu-pagina. De gebruiker kan z'n
 * eigen geüploade bestanden inzien en losse items naar de prullenbak sturen
 * (soft-delete op user_uploads). De TrashController kent ze als type "media"
 * — daar restore't of force-delete't de gebruiker ze.
 *
 * Belangrijk: we verwijderen het fysieke bestand pas wanneer trash:purge
 * de regel daadwerkelijk wegtikt; tot die tijd kan de gebruiker 'm
 * herstellen zonder gevolg voor opslagverbruik (de stats sluiten soft-
 * deleted rijen niet uit; zie StatsController).
 */
class MediaController extends Controller
{
    public function index(Request $request)
    {
        $uid = Auth::id();
        $request->validate([
            'kind' => 'nullable|string|in:chat,routemap,mission_logo,other',
            'per'  => 'nullable|integer|min:1|max:200',
        ]);

        $q = UserUpload::where('user_id', $uid);
        if ($kind = $request->query('kind')) {
            $q->where('kind', $kind);
        }
        $q->orderByDesc('id');
        $per = (int) $request->query('per', 50);

        $items = $q->limit($per)->get();

        return response()->json([
            'items' => $items->map(fn ($u) => [
                'id'         => $u->id,
                'kind'       => $u->kind,
                'path'       => $u->path,
                'url'        => $this->resolveUrl($u),
                'mime'       => $u->mime,
                'size'       => (int) $u->size,
                'created_at' => optional($u->created_at)->toIso8601String(),
            ])->values(),
            'total_bytes' => (int) UserUpload::where('user_id', $uid)->sum('size'),
        ]);
    }

    public function destroy(int $id)
    {
        $uid = Auth::id();
        $u = UserUpload::where('user_id', $uid)->findOrFail($id);
        // Soft-delete: belandt in /trash. Het fysieke bestand staat nog
        // gewoon op de disk — pas trash:purge ruimt 'm op.
        $u->delete();
        return response()->json(['ok' => true]);
    }

    protected function resolveUrl(UserUpload $u): ?string
    {
        try {
            return Storage::disk($u->disk ?: 'public')->url($u->path);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
