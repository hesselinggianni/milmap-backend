<?php

namespace App\Http\Controllers;

use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class FeatureRequestController extends Controller
{
    /** Toegestane triage-statussen voor het admin-panel. */
    public const STATUSES = ['new', 'planned', 'in_progress', 'done', 'declined'];

    /** Toegestane prioriteiten (komt overeen met het publieke formulier). */
    public const PRIORITIES = ['low', 'medium', 'high'];

    /**
     * POST /api/v1/feature-requests — publiek (throttled).
     *
     * Bewaart het verzoek én mailt het (zoals voorheen) naar de beheerder. Het
     * verzoek wordt gekoppeld aan een bestaand account wanneer het e-mailadres
     * overeenkomt, zodat het admin-panel kan tonen of de indiener al gebruiker is.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_name' => 'required|string|max:255',
                'user_email' => 'required|email|max:255',
                'title' => 'required|string|max:255',
                'description' => 'required|string|min:10|max:5000',
                'priority' => 'nullable|in:low,medium,high',
                'category' => 'nullable|string|max:100',
            ]);

            $email = mb_strtolower(trim($validated['user_email']));
            $priority = $validated['priority'] ?? 'medium';

            // Koppel aan een bestaand account met hetzelfde e-mailadres (indien aanwezig).
            $userId = User::whereRaw('LOWER(email) = ?', [$email])->value('id');

            $featureRequest = FeatureRequest::create([
                'user_id' => $userId,
                'user_name' => $validated['user_name'],
                'user_email' => $email,
                'title' => $validated['title'],
                'description' => $validated['description'],
                'priority' => $priority,
                'category' => $validated['category'] ?? null,
                'status' => 'new',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            // E-mailnotificatie is "best effort": faalt de mail, dan blijft het
            // verzoek netjes opgeslagen i.p.v. de hele request te laten klappen.
            try {
                $this->sendEmail([
                    'user_name' => $validated['user_name'],
                    'user_email' => $email,
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'priority' => $priority,
                    'category' => $validated['category'] ?? null,
                    'timestamp' => now()->setTimezone('Europe/Amsterdam')->format('d-m-Y \o\m H:i:s'),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Feature request mail failed: '.$e->getMessage());
            }

            return response()->json([
                'message' => 'Feature request received successfully',
                'data' => [
                    'id' => $featureRequest->id,
                    'user_name' => $featureRequest->user_name,
                    'user_email' => $featureRequest->user_email,
                    'title' => $featureRequest->title,
                    'timestamp' => optional($featureRequest->created_at)->toIso8601String(),
                ],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error processing feature request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/admin/feature-requests — admin-only overzicht met filters.
     * Query params:
     *   q        — substring match op titel / e-mail / naam
     *   status   — exact filter (new|planned|in_progress|done|declined)
     *   priority — exact filter (low|medium|high)
     *   page/per — paginering
     */
    public function adminIndex(Request $request)
    {
        $request->validate([
            'q' => 'nullable|string|max:255',
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'priority' => ['nullable', Rule::in(self::PRIORITIES)],
            'page' => 'nullable|integer|min:1',
            'per' => 'nullable|integer|min:1|max:200',
        ]);

        $per = (int) $request->query('per', 25);

        $query = FeatureRequest::query();

        if ($qs = $request->query('q')) {
            $query->where(function ($w) use ($qs) {
                $w->where('title', 'like', '%'.$qs.'%')
                    ->orWhere('user_email', 'like', '%'.$qs.'%')
                    ->orWhere('user_name', 'like', '%'.$qs.'%');
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }

        $page = $query->orderByDesc('id')->paginate($per);

        // Koppel elke rij aan een bestaand account op e-mail. Dit vangt ook
        // indieners die zich ná hun verzoek registreerden (waar user_id nog
        // null is). Eén batch-query op de zichtbare pagina — geen N+1.
        $emails = collect($page->items())
            ->map(fn ($r) => mb_strtolower(trim($r->user_email)))
            ->unique()->filter()->values();

        $usersByEmail = $emails->isEmpty()
            ? collect()
            : User::whereIn(DB::raw('LOWER(email)'), $emails->all())
                ->get()
                ->keyBy(fn ($u) => mb_strtolower($u->email));

        $data = collect($page->items())->map(function ($r) use ($usersByEmail) {
            $user = $usersByEmail->get(mb_strtolower(trim($r->user_email)));

            return [
                'id' => $r->id,
                'user_name' => $r->user_name,
                'user_email' => $r->user_email,
                'title' => $r->title,
                'description' => $r->description,
                'priority' => $r->priority,
                'category' => $r->category,
                'status' => $r->status,
                'admin_note' => $r->admin_note,
                'created_at' => optional($r->created_at)->toIso8601String(),
                // Aanwezig wanneer de inzender een bestaand MilMap-account heeft.
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => trim($user->first_name.' '.$user->last_name) ?: $user->email,
                    'email' => $user->email,
                    'plan' => $user->plan(),
                ] : null,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per' => $page->perPage(),
                'pages' => $page->lastPage(),
            ],
            'counts' => $this->statusCounts(),
        ]);
    }

    /**
     * PUT /api/v1/admin/feature-requests/{id} — werk triage-velden bij
     * (status, prioriteit, interne notitie).
     */
    public function adminUpdate(Request $request, int $id)
    {
        $featureRequest = FeatureRequest::findOrFail($id);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'priority' => ['sometimes', Rule::in(self::PRIORITIES)],
            'admin_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $featureRequest->fill($validated)->save();

        return response()->json([
            'ok' => true,
            'feature_request' => [
                'id' => $featureRequest->id,
                'status' => $featureRequest->status,
                'priority' => $featureRequest->priority,
                'admin_note' => $featureRequest->admin_note,
            ],
        ]);
    }

    /**
     * DELETE /api/v1/admin/feature-requests/{id}
     */
    public function adminDestroy(int $id)
    {
        FeatureRequest::where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** Aantallen per status, voor de filter-badges in het admin-panel. */
    private function statusCounts(): array
    {
        $counts = FeatureRequest::selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $out = ['all' => (int) $counts->sum()];
        foreach (self::STATUSES as $s) {
            $out[$s] = (int) ($counts[$s] ?? 0);
        }

        return $out;
    }

    private function sendEmail(array $data)
    {
        $adminEmail = 'gianni@onavan.com';

        Mail::send('emails.feature-request', ['data' => $data], function ($message) use ($adminEmail, $data) {
            $message->to($adminEmail)
                ->subject('Feature Request: '.$data['title'])
                ->replyTo($data['user_email'], $data['user_name']);
        });
    }
}
