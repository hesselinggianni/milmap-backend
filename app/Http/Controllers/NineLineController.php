<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\NineLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Server-side store for MEDEVAC 9-liners: overview, edit, audit log, archive.
 *
 * Scope ("Alle in mijn missiegroepen"): a user sees every 9-liner attached to a
 * conversation they participate in, plus any they submitted themselves.
 *
 * Edit rights ("Inzender + missiegroep-leden"): the submitter OR any
 * participant of the linked conversation may edit / archive. Both checks live
 * in canAccess(), which is therefore reused for read, edit and archive.
 *
 * Every mutation appends a NineLineRevision so the detail page can render a
 * "what changed, by whom, when" log. The human-readable "bijgewerkt"-melding in
 * the chat is posted by the CLIENT after a successful edit (the chat is E2EE,
 * so only the client can compose an encrypted message).
 */
class NineLineController extends Controller
{
    /** Fields whose changes are tracked in the audit log. */
    private const TRACKED = ['title', 'ref', 'mode', 'exercise', 'lines'];

    /**
     * GET /nine-lines
     * Query params: ?archived=1 (default: active only), ?q=<search>.
     */
    public function index(Request $request)
    {
        $uid = Auth::id();

        $convIds = $this->myConversationIds($uid);

        $query = NineLine::with('user:id,first_name,last_name,email')
            ->where(function ($q) use ($convIds, $uid) {
                $q->whereIn('conversation_id', $convIds)
                  ->orWhere('user_id', $uid);
            });

        // Active vs archived tab.
        $query->where('status', $request->boolean('archived') ? 'archived' : 'active');

        // Free-text search across title, reference and the line values. The
        // lines column is JSON; a LIKE against its serialized text is good
        // enough for an overview search.
        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('ref', 'like', "%{$q}%")
                    ->orWhere('lines', 'like', "%{$q}%");
            });
        }

        return response()->json(
            $query->orderBy('created_at', 'desc')->get()
                ->map(fn ($n) => $this->present($n))->all()
        );
    }

    /**
     * POST /nine-lines
     * Persist a 9-liner (called by the client right after it delivers the
     * E2EE chat copy). Records a 'created' audit entry.
     */
    public function store(Request $request)
    {
        $uid = Auth::id();

        $validated = $request->validate([
            'conversation_id' => 'nullable|uuid',
            'mission_id'      => 'nullable|uuid',
            'ref'             => 'nullable|string|max:120',
            'title'           => 'nullable|string|max:160',
            'mode'            => 'nullable|string|in:exercise,noplay',
            'exercise'        => 'nullable|boolean',
            'lines'           => 'required|array|min:1',
            'lines.*.n'       => 'nullable',
            'lines.*.label'   => 'nullable|string',
            'lines.*.value'   => 'nullable',
        ]);

        // If the 9-liner is tied to a conversation, the submitter must be a
        // participant of it (you can't post into a chat you're not in).
        if (!empty($validated['conversation_id'])
            && !$this->isParticipant($validated['conversation_id'], $uid)) {
            return response()->json(['message' => 'Geen toegang tot deze chat.'], 403);
        }

        $mode = $validated['mode'] ?? 'noplay';

        $nineLine = NineLine::create([
            'conversation_id' => $validated['conversation_id'] ?? null,
            'mission_id'      => $validated['mission_id'] ?? null,
            'user_id'         => $uid,
            'ref'             => $validated['ref'] ?? null,
            'title'           => $validated['title'] ?? 'MEDEVAC 9-liner',
            'mode'            => $mode,
            'exercise'        => $validated['exercise'] ?? ($mode === 'exercise'),
            'lines'           => $validated['lines'],
            'status'          => 'active',
        ]);

        $nineLine->revisions()->create([
            'user_id' => $uid,
            'action'  => 'created',
            'changes' => null,
        ]);

        $nineLine->load('user:id,first_name,last_name,email');
        return response()->json($this->present($nineLine), 201);
    }

    /**
     * GET /nine-lines/{id}
     * Full record incl. the audit log (each revision with its actor's name).
     */
    public function show($id)
    {
        $uid = Auth::id();
        $nineLine = NineLine::with([
            'user:id,first_name,last_name,email',
            'revisions.user:id,first_name,last_name,email',
        ])->findOrFail($id);

        if (!$this->canAccess($nineLine, $uid)) {
            return response()->json(['message' => 'Geen toegang.'], 403);
        }

        return response()->json($this->present($nineLine, true));
    }

    /**
     * PUT /nine-lines/{id}
     * Edit by submitter or a participant of the linked conversation. Diffs the
     * tracked fields and records an 'updated' audit entry (only if something
     * actually changed).
     */
    public function update(Request $request, $id)
    {
        $uid = Auth::id();
        $nineLine = NineLine::findOrFail($id);

        if (!$this->canAccess($nineLine, $uid)) {
            return response()->json(['message' => 'Geen toegang.'], 403);
        }

        $validated = $request->validate([
            'ref'           => 'nullable|string|max:120',
            'title'         => 'sometimes|string|max:160',
            'mode'          => 'sometimes|string|in:exercise,noplay',
            'exercise'      => 'sometimes|boolean',
            'lines'         => 'sometimes|array|min:1',
            'lines.*.n'     => 'nullable',
            'lines.*.label' => 'nullable|string',
            'lines.*.value' => 'nullable',
        ]);

        // Compute a field-level diff over the tracked fields.
        $changes = [];
        foreach (self::TRACKED as $field) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }
            $old = $nineLine->$field;
            $new = $validated[$field];
            // PHP compares arrays recursively by key/value, so this catches
            // edits to individual lines too.
            if ($old != $new) {
                $changes[$field] = ['from' => $old, 'to' => $new];
            }
        }

        if (!empty($changes)) {
            $nineLine->update($validated);
            $nineLine->revisions()->create([
                'user_id' => $uid,
                'action'  => 'updated',
                'changes' => $changes,
            ]);
        }

        return response()->json($this->present($nineLine->fresh([
            'user:id,first_name,last_name,email',
            'revisions.user:id,first_name,last_name,email',
        ]), true));
    }

    /**
     * POST /nine-lines/{id}/archive
     */
    public function archive($id)
    {
        return $this->setArchived($id, true);
    }

    /**
     * POST /nine-lines/{id}/unarchive
     */
    public function unarchive($id)
    {
        return $this->setArchived($id, false);
    }

    // ── Presentation ───────────────────────────────────────────────

    /**
     * The users table has no `name` column (first_name + last_name + email);
     * collapse it to a single display `name` so the client has one field. Falls
     * back to the e-mail when no name is set (matches the User::full_name
     * accessor used elsewhere).
     */
    private function presentUser($u): ?array
    {
        if (!$u) {
            return null;
        }
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        return [
            'id'   => $u->id,
            'name' => $name !== '' ? $name : ($u->email ?? 'Onbekend'),
        ];
    }

    /**
     * Shape a NineLine for the client: a flat record with a collapsed `user`
     * (and, optionally, its revisions each with their actor).
     */
    private function present(NineLine $n, bool $withRevisions = false): array
    {
        $data = [
            'id'              => $n->id,
            'conversation_id' => $n->conversation_id,
            'mission_id'      => $n->mission_id,
            'user_id'         => $n->user_id,
            'user'            => $this->presentUser($n->user),
            'ref'             => $n->ref,
            'title'           => $n->title,
            'mode'            => $n->mode,
            'exercise'        => (bool) $n->exercise,
            'lines'           => $n->lines,
            'status'          => $n->status,
            'archived_at'     => optional($n->archived_at)->toISOString(),
            'created_at'      => optional($n->created_at)->toISOString(),
            'updated_at'      => optional($n->updated_at)->toISOString(),
        ];

        if ($withRevisions) {
            $data['revisions'] = $n->revisions->map(fn ($r) => [
                'id'         => $r->id,
                'action'     => $r->action,
                'changes'    => $r->changes,
                'user'       => $this->presentUser($r->user),
                'created_at' => optional($r->created_at)->toISOString(),
            ])->all();
        }

        return $data;
    }

    // ── Internals ──────────────────────────────────────────────────

    private function setArchived($id, bool $archived)
    {
        $uid = Auth::id();
        $nineLine = NineLine::findOrFail($id);

        if (!$this->canAccess($nineLine, $uid)) {
            return response()->json(['message' => 'Geen toegang.'], 403);
        }

        $nineLine->update([
            'status'      => $archived ? 'archived' : 'active',
            'archived_at' => $archived ? now() : null,
        ]);

        $nineLine->revisions()->create([
            'user_id' => $uid,
            'action'  => $archived ? 'archived' : 'unarchived',
            'changes' => null,
        ]);

        return response()->json($this->present($nineLine->fresh([
            'user:id,first_name,last_name,email',
            'revisions.user:id,first_name,last_name,email',
        ]), true));
    }

    /**
     * Conversation IDs the user participates in — the basis for the
     * "alle in mijn missiegroepen" scope.
     */
    private function myConversationIds(int $uid)
    {
        return Conversation::whereHas('participants', fn ($q) => $q->where('users.id', $uid))
            ->pluck('id');
    }

    private function isParticipant(?string $conversationId, int $uid): bool
    {
        if (!$conversationId) {
            return false;
        }
        $conv = Conversation::find($conversationId);
        return $conv ? $conv->hasParticipant($uid) : false;
    }

    /**
     * Read + edit + archive permission: submitter OR a participant of the
     * linked conversation ("Inzender + missiegroep-leden").
     */
    private function canAccess(NineLine $nineLine, int $uid): bool
    {
        if ((int) $nineLine->user_id === $uid) {
            return true;
        }
        return $this->isParticipant($nineLine->conversation_id, $uid);
    }
}
