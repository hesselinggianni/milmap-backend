<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Publieke leads-API — gebruikers die via milmap.nl/app het e-mailformulier
 * invullen om op de hoogte te blijven van de release. Geen account; alleen
 * e-mail + lichte attributie (source label + utm_source = share_uuid van een
 * doorverwijzer). Resource leeft in een aparte tabel zodat het admin-overzicht
 * niet vermengt met echte gebruikers.
 *
 * Het admin-zicht (LeadController::adminIndex) hangt onder /admin/leads en
 * vereist admin.auth.
 */
class LeadController extends Controller
{
    /**
     * POST /api/v1/leads — publiek (throttled). Idempotent: bestaande e-mail
     * krijgt 200 ok zonder error, zodat een dubbele tap niet vervelend voelt.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'email'      => ['required', 'email', 'max:255'],
            'source'     => ['nullable', 'string', 'max:64'],
            'utm_source' => ['nullable', 'string', 'max:64'],
        ]);

        $email = mb_strtolower(trim($data['email']));

        $lead = Lead::firstOrCreate(
            ['email' => $email],
            [
                'source'     => $data['source'] ?? 'app-landing',
                'utm_source' => $data['utm_source'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ],
        );

        return response()->json([
            'ok'       => true,
            'is_new'   => $lead->wasRecentlyCreated,
            'message'  => $lead->wasRecentlyCreated
                ? 'Bedankt! Je hoort van ons zodra MilMap live gaat.'
                : 'Je staat al op de lijst — we sturen je een mail zodra MilMap live gaat.',
        ]);
    }

    /**
     * GET /api/v1/admin/leads — admin-only overzicht met paginering + filters.
     * Query params:
     *   q       — substring match op e-mail
     *   source  — exact filter op de source-label
     *   pending — 1 → alleen leads zonder notified_at
     */
    public function adminIndex(Request $request)
    {
        $request->validate([
            'q'       => 'nullable|string|max:255',
            'source'  => 'nullable|string|max:64',
            'pending' => 'nullable|in:0,1',
            'page'    => 'nullable|integer|min:1',
            'per'     => 'nullable|integer|min:1|max:200',
        ]);

        $per = (int) $request->query('per', 25);

        $q = Lead::query();
        if ($qs = $request->query('q')) {
            $q->where('email', 'like', '%' . $qs . '%');
        }
        if ($s = $request->query('source')) {
            $q->where('source', $s);
        }
        if ($request->query('pending') === '1') {
            $q->whereNull('notified_at');
        }

        $page = $q->orderByDesc('id')->paginate($per);

        return response()->json([
            'data' => collect($page->items())->map(fn ($l) => [
                'id'          => $l->id,
                'email'       => $l->email,
                'source'      => $l->source,
                'utm_source'  => $l->utm_source,
                'ip_address'  => $l->ip_address,
                'notified_at' => optional($l->notified_at)->toIso8601String(),
                'created_at'  => optional($l->created_at)->toIso8601String(),
            ])->values(),
            'meta' => [
                'total' => $page->total(),
                'page'  => $page->currentPage(),
                'per'   => $page->perPage(),
                'pages' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Markeer één lead als "we hebben 'm geïnformeerd". Optioneel handmatig
     * vanuit het admin-paneel; de daadwerkelijke release-mail-stack komt
     * later, dit endpoint is voor handmatige bewerking.
     */
    public function adminMarkNotified(Request $request, int $id)
    {
        $lead = Lead::findOrFail($id);
        $lead->notified_at = now();
        $lead->save();
        return response()->json(['ok' => true]);
    }

    /**
     * Verwijder een lead — bv. bij uitschrijven-verzoek.
     */
    public function adminDestroy(int $id)
    {
        Lead::where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }
}
