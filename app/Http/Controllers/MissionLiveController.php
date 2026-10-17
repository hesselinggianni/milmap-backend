<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Models\MissionFieldLock;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Samen een missie bewerken: live inhoud + veld-locks.
 *
 * Draait bewust op polling (prod heeft geen Reverb, zie
 * memory/prod-server-config-gotchas): de client vraagt elke ~2s de stand op.
 *
 *   GET  /api/v1/missions/{id}/live?hash=…  — alleen lezen (ook viewers en
 *        view-only-uitgenodigden, die geen POST mogen; EnsureNotViewOnly)
 *   POST /api/v1/missions/{id}/live         — schrijven: claim/held/release,
 *        per-veld-patches op de ogroup-blob en overnameverzoeken
 *
 * Inhoud: de ogroup-blob wordt niet meer in z'n geheel overschreven maar per
 * pad gepatcht (in een transactie met lockForUpdate), zodat twee mensen in
 * verschillende velden elkaar niet overschrijven. Een patch op een veld dat
 * een ánder vasthoudt wordt geweigerd (rejected) — dat is de echte handhaving
 * van de lock, de UI-blokkade is alleen gemak. `hash` = md5 van de opgeslagen
 * blob; alleen als die afwijkt van wat de client heeft sturen we 'm mee. Zo
 * vangen we ook volledige PUT's van oudere app-versies op, zonder extra kolom.
 *
 * Locks: veldpad (bv. "warning_order.situation") → houder + expires_at. De
 * client stuurt heartbeats (`held`) zolang de focus erop staat. Overname:
 * `takeover` zet een verzoek klaar, de houder antwoordt via `respond`; geen
 * antwoord binnen TAKEOVER_TIMEOUT → gaat het veld alsnog over (houder is
 * vermoedelijk weg).
 */
class MissionLiveController extends Controller
{
    /** Seconden zonder heartbeat waarna een lock vervalt. */
    public const LOCK_TTL = 30;

    /** Seconden waarna een onbeantwoord overnameverzoek automatisch doorgaat. */
    public const TAKEOVER_TIMEOUT = 15;

    private const FIELD_RULE = ['string', 'max:191', 'regex:/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/'];

    public function show(Request $request, $id)
    {
        $mission = Mission::findOrFail($id);
        $userId = (int) Auth::id();
        if (! $mission->hasAccess($userId)) {
            abort(403, 'You do not have access to this mission');
        }

        $this->housekeeping((string) $mission->id);

        return response()->json($this->state($mission, $userId, (string) $request->query('hash', ''), [], []));
    }

    public function sync(Request $request, $id)
    {
        $mission = Mission::findOrFail($id);
        $userId = (int) Auth::id();
        if (! $mission->canEdit($userId)) {
            abort(403, 'You do not have permission to edit this mission');
        }

        $data = $request->validate([
            'hash'            => 'nullable|string|max:64',
            'claim'           => 'sometimes|array|max:10',
            'claim.*'         => self::FIELD_RULE,
            'held'            => 'sometimes|array|max:30',
            'held.*'          => self::FIELD_RULE,
            'release'         => 'sometimes|array|max:30',
            'release.*'       => self::FIELD_RULE,
            // JSON-string i.p.v. geneste array: de globale TrimStrings- en
            // ConvertEmptyStringsToNull-middleware zouden anders spaties van
            // tekstvelden afknippen en "" in null veranderen — dan ziet de
            // client z'n eigen waarde nooit terug en blijft hij patchen.
            'patches'         => 'sometimes|nullable|string|max:2000000',
            'takeover'        => array_merge(['sometimes', 'nullable'], self::FIELD_RULE),
            'cancel_takeover' => array_merge(['sometimes', 'nullable'], self::FIELD_RULE),
            'respond'         => 'sometimes|array|max:10',
            'respond.*.field' => array_merge(['required'], self::FIELD_RULE),
            'respond.*.allow' => 'required|boolean',
        ]);

        $patches = $this->parsePatches($data['patches'] ?? null);
        $missionId = (string) $mission->id;
        $locksOn = $this->locksSupported();
        $claimFailed = [];
        $rejected = [];

        if ($locksOn) {
            $this->housekeeping($missionId);
            foreach (array_unique($data['claim'] ?? []) as $field) {
                if (! $this->claim($missionId, $field, $userId)) {
                    $claimFailed[] = $field;
                }
            }
            if (! empty($data['held'])) {
                MissionFieldLock::where('mission_id', $missionId)->where('user_id', $userId)
                    ->whereIn('field', $data['held'])
                    ->update(['expires_at' => now()->addSeconds(self::LOCK_TTL)]);
            }
        }

        if ($patches) {
            $rejected = $this->applyPatches($missionId, $patches, $userId, $locksOn);
            $mission->refresh();
        }

        if ($locksOn) {
            // Na de patches: zo staat de laatste tekst van de houder er al in
            // vóórdat het veld wordt vrijgegeven of overgedragen.
            foreach ($data['respond'] ?? [] as $r) {
                $this->respond($missionId, $r['field'], $userId, (bool) $r['allow']);
            }
            if (! empty($data['release'])) {
                MissionFieldLock::where('mission_id', $missionId)->where('user_id', $userId)
                    ->whereIn('field', $data['release'])->delete();
            }
            if (! empty($data['cancel_takeover'])) {
                MissionFieldLock::where('mission_id', $missionId)->where('field', $data['cancel_takeover'])
                    ->where('takeover_user_id', $userId)
                    ->update(['takeover_user_id' => null, 'takeover_requested_at' => null, 'takeover_status' => null]);
            }
            if (! empty($data['takeover'])) {
                $this->requestTakeover($missionId, $data['takeover'], $userId);
            }
        }

        return response()->json($this->state($mission, $userId, (string) ($data['hash'] ?? ''), $rejected, $claimFailed));
    }

    // ── Locks ────────────────────────────────────────────────────────────

    /**
     * Ruim verlopen locks op en zet overnameverzoeken door waar de houder
     * niet binnen TAKEOVER_TIMEOUT reageerde.
     */
    private function housekeeping(string $missionId): void
    {
        if (! $this->locksSupported()) {
            return;
        }
        MissionFieldLock::where('mission_id', $missionId)->where('expires_at', '<', now())->delete();
        MissionFieldLock::where('mission_id', $missionId)
            ->where('takeover_status', 'pending')
            ->where('takeover_requested_at', '<', now()->subSeconds(self::TAKEOVER_TIMEOUT))
            ->get()
            ->each(fn (MissionFieldLock $l) => $this->transfer($l));
    }

    private function claim(string $missionId, string $field, int $userId): bool
    {
        $lock = MissionFieldLock::where('mission_id', $missionId)->where('field', $field)->first();
        if ($lock) {
            if ($lock->user_id !== $userId) {
                return false;
            }
            $lock->update(['expires_at' => now()->addSeconds(self::LOCK_TTL)]);

            return true;
        }
        try {
            MissionFieldLock::create([
                'mission_id' => $missionId,
                'field'      => $field,
                'user_id'    => $userId,
                'expires_at' => now()->addSeconds(self::LOCK_TTL),
            ]);

            return true;
        } catch (QueryException $e) {
            // Unieke sleutel: iemand anders was ons net voor.
            return MissionFieldLock::where('mission_id', $missionId)->where('field', $field)
                ->where('user_id', $userId)->exists();
        }
    }

    private function requestTakeover(string $missionId, string $field, int $userId): void
    {
        $lock = MissionFieldLock::where('mission_id', $missionId)->where('field', $field)->first();
        if (! $lock) {
            $this->claim($missionId, $field, $userId); // inmiddels vrij

            return;
        }
        if ($lock->user_id === $userId) {
            return;
        }
        // Lopend verzoek van een ander gaat voor; een eerder geweigerd verzoek
        // (ook van een ander) mag opnieuw.
        if ($lock->takeover_status === 'pending' && $lock->takeover_user_id !== $userId) {
            return;
        }
        $lock->update([
            'takeover_user_id'      => $userId,
            'takeover_requested_at' => now(),
            'takeover_status'       => 'pending',
        ]);
    }

    private function respond(string $missionId, string $field, int $userId, bool $allow): void
    {
        $lock = MissionFieldLock::where('mission_id', $missionId)->where('field', $field)
            ->where('user_id', $userId)->where('takeover_status', 'pending')->first();
        if (! $lock) {
            return;
        }
        if ($allow) {
            $this->transfer($lock);
        } else {
            // Blijft staan tot de aanvrager de weigering heeft opgehaald.
            $lock->update(['takeover_status' => 'denied']);
        }
    }

    private function transfer(MissionFieldLock $lock): void
    {
        $lock->update([
            'user_id'               => $lock->takeover_user_id,
            'expires_at'            => now()->addSeconds(self::LOCK_TTL),
            'takeover_user_id'      => null,
            'takeover_requested_at' => null,
            'takeover_status'       => null,
        ]);
    }

    /** Tabel nog niet gemigreerd op deze omgeving? Dan alleen inhoud-sync. */
    private function locksSupported(): bool
    {
        static $supported = null;
        if ($supported === null) {
            $supported = (bool) Cache::remember('schema:mission_field_locks', 600,
                fn () => Schema::hasTable('mission_field_locks'));
        }

        return $supported;
    }

    // ── Inhoud ───────────────────────────────────────────────────────────

    /** @return array<int, array{path: string[], value: mixed}> */
    private function parsePatches(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $list = json_decode($json, true);
        if (! is_array($list) || count($list) > 1000) {
            abort(422, 'Invalid patches');
        }
        $out = [];
        foreach ($list as $p) {
            $path = $p['path'] ?? null;
            if (! is_array($path) || ! $path || count($path) > 12 || ! array_key_exists('value', $p)) {
                abort(422, 'Invalid patch');
            }
            foreach ($path as $seg) {
                if (! is_string($seg) || $seg === '' || strlen($seg) > 100) {
                    abort(422, 'Invalid patch path');
                }
            }
            $out[] = ['path' => array_values($path), 'value' => $p['value']];
        }

        return $out;
    }

    /**
     * Pas patches toe op de opgeslagen blob. Geeft de geweigerde paden terug
     * (veld vastgehouden door een ander).
     *
     * @return string[]
     */
    private function applyPatches(string $missionId, array $patches, int $userId, bool $locksOn): array
    {
        return DB::transaction(function () use ($missionId, $patches, $userId, $locksOn) {
            $mission = Mission::whereKey($missionId)->lockForUpdate()->firstOrFail();

            $foreign = $locksOn
                ? MissionFieldLock::where('mission_id', $missionId)->where('user_id', '!=', $userId)
                    ->where('expires_at', '>=', now())->pluck('field')
                    ->map(fn ($f) => explode('.', $f))->all()
                : [];

            $blob = is_array($mission->ogroup) ? $mission->ogroup : [];
            $rejected = [];
            foreach ($patches as $p) {
                if ($this->conflicts($p['path'], $foreign)) {
                    $rejected[] = implode('.', $p['path']);
                    continue;
                }
                $this->setPath($blob, $p['path'], $p['value']);
            }

            if (count($rejected) < count($patches)) {
                $mission->ogroup = $blob;
                $mission->save();
            }

            return $rejected;
        });
    }

    /** Overlapt het pad met een vastgehouden veld (een van beide prefix van de ander)? */
    private function conflicts(array $path, array $lockedPaths): bool
    {
        foreach ($lockedPaths as $locked) {
            $n = min(count($path), count($locked));
            if (array_slice($path, 0, $n) === array_slice($locked, 0, $n)) {
                return true;
            }
        }

        return false;
    }

    private function setPath(array &$blob, array $path, $value): void
    {
        $ref = &$blob;
        foreach (array_slice($path, 0, -1) as $seg) {
            if (! isset($ref[$seg]) || ! is_array($ref[$seg])) {
                $ref[$seg] = [];
            }
            $ref = &$ref[$seg];
        }
        $ref[end($path)] = $value;
    }

    // ── Response ─────────────────────────────────────────────────────────

    private function state(Mission $mission, int $userId, string $clientHash, array $rejected, array $claimFailed): array
    {
        $blob = $mission->ogroup;
        $hash = md5(json_encode($blob));
        $out = [
            'hash'         => $hash,
            'rejected'     => $rejected,
            'claim_failed' => $claimFailed,
            'locks'        => [],
            'denied'       => [],
            'lock_ttl'     => self::LOCK_TTL,
            'takeover_timeout' => self::TAKEOVER_TIMEOUT,
            'locks_supported'  => $this->locksSupported(),
        ];
        if ($hash !== $clientHash) {
            $out['ogroup'] = $blob;
        }
        if (! $this->locksSupported()) {
            return $out;
        }

        $locks = MissionFieldLock::where('mission_id', $mission->id)->where('expires_at', '>=', now())->get();
        $userIds = $locks->pluck('user_id')->merge($locks->pluck('takeover_user_id'))->filter()->unique();
        $users = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name', 'email', 'avatar_path'])
            ->keyBy('id')
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->full_name, 'avatar_url' => $u->avatar_url]);
        $user = fn ($id) => $users[$id] ?? ['id' => $id, 'name' => null, 'avatar_url' => null];

        $deniedIds = [];
        foreach ($locks as $l) {
            if ($l->takeover_status === 'denied') {
                if ($l->takeover_user_id === $userId) {
                    $out['denied'][] = ['field' => $l->field, 'user' => $user($l->user_id)];
                    $deniedIds[] = $l->id;
                }
                $takeover = null;
            } elseif ($l->takeover_status === 'pending') {
                $takeover = [
                    'user'         => $user($l->takeover_user_id),
                    'mine'         => $l->takeover_user_id === $userId,
                    'seconds_left' => max(0, self::TAKEOVER_TIMEOUT - (int) $l->takeover_requested_at->diffInSeconds(now(), true)),
                ];
            } else {
                $takeover = null;
            }
            $out['locks'][] = [
                'field'    => $l->field,
                'user'     => $user($l->user_id),
                'mine'     => $l->user_id === $userId,
                'takeover' => $takeover,
            ];
        }
        // Weigering is afgeleverd → opruimen, zodat de aanvrager 'm één keer ziet.
        if ($deniedIds) {
            MissionFieldLock::whereIn('id', $deniedIds)
                ->update(['takeover_user_id' => null, 'takeover_requested_at' => null, 'takeover_status' => null]);
        }

        return $out;
    }
}
