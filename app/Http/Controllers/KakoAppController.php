<?php

namespace App\Http\Controllers;

use App\Models\KakoAppCheckin;
use App\Models\KakoAppEvent;
use App\Models\KakoAppParticipant;
use App\Models\KakoAppPosition;
use App\Models\Map;
use App\Models\RouteAssignment;
use App\Models\RouteAssignmentCollaborator;
use App\Models\RouteAssignmentLog;
use App\Models\RouteMap;
use App\Models\User;
use App\Support\GpsValues;
use App\Support\KakoAppRules as Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * KAKO app-modus: deelnemers lopen een opdracht in de app (check-in per prikker,
 * eigen route, positie delen met toestemming) en de instructeur ziet check-ins en
 * posities. Ontwerp, privacykeuzes en fasering: docs/kako-app-modus.md.
 *
 * Twee kanten, bewust strikt gescheiden:
 *  - DEELNEMER (eigen kako_app_participants-rij, status accepted). Krijgt nooit de
 *    opdracht zelf, alleen z'n eigen route zonder prikker-coördinaten.
 *  - BEHEERDER = eigenaar of geaccepteerde, expliciete editor-collaborator. De
 *    impliciete 'editor' via kaartrechten (RouteAssignmentController::roleFor) is
 *    hier te ruim: die zou live posities van deelnemers zien.
 */
class KakoAppController extends Controller
{
    private const INVITE_DAYS = 14;
    private const INVITE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const MAX_SAMPLES = 200;

    // ── Gedeelde helpers ──────────────────────────────────────────────

    private function assignment(string $id): RouteAssignment
    {
        return RouteAssignment::findOrFail($id);
    }

    /** Eigenaar, of geaccepteerde expliciete editor-collaborator (niet: kijker, niet: kaartrechten). */
    private function canManage(RouteAssignment $a): bool
    {
        $uid = auth()->id();
        if (! $uid) {
            return false;
        }
        if ((int) $a->owner_id === (int) $uid) {
            return true;
        }

        return RouteAssignmentCollaborator::where('route_assignment_id', $a->id)
            ->where('user_id', $uid)->where('status', 'accepted')->where('role', 'editor')->exists();
    }

    private function manager(string $id): RouteAssignment
    {
        $a = $this->assignment($id);
        abort_unless($this->canManage($a), 403, 'Geen toegang tot de deelnemersgegevens van deze KAKO-training');

        return $a;
    }

    /**
     * De eigen deelnemer-rij. Wie bij 'verplicht delen' voor papier koos (`declined`) mag z'n
     * keuze nog herzien: alleen toestemming en inzage staan hem dan open, check-ins en posities niet.
     */
    private function participantOf(RouteAssignment $a, array $statuses = ['accepted']): KakoAppParticipant
    {
        $p = KakoAppParticipant::where('route_assignment_id', $a->id)
            ->where('user_id', auth()->id())->whereIn('status', $statuses)->first();
        abort_unless($p, 404, 'Je neemt niet deel aan deze opdracht');

        return $p;
    }

    private function logEvent(string $assignmentId, ?KakoAppParticipant $p, string $type, array $data = []): void
    {
        $u = auth()->user();
        KakoAppEvent::create([
            'route_assignment_id' => $assignmentId,
            'participant_id' => $p?->id,
            'actor_user_id' => $u?->id,
            'actor_name' => $u ? (trim((string) $u->full_name) ?: null) : null,
            'type' => $type,
            'data' => $data ?: null,
            'created_at' => now(),
        ]);
    }

    /** Toegangslog voor de deelnemer ("wie keek mee"): gedempt tot één regel per kijker per 10 minuten. */
    private function logAccess(RouteAssignment $a, ?KakoAppParticipant $p, string $type): void
    {
        $key = "kako_access:{$type}:{$a->id}:" . ($p?->id ?? 'all') . ':' . auth()->id();
        if (Cache::add($key, 1, now()->addMinutes(10))) {
            $this->logEvent($a->id, $p, $type);
        }
    }

    private function iso($dt): ?string
    {
        return $dt ? Carbon::parse($dt)->utc()->toIso8601ZuluString('millisecond') : null;
    }

    /** Tijdstip uit de client (ISO-tekst of epoch-ms) in de opslagtijdzone; null bij onzin. */
    private function ts(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $c = is_numeric($value) ? Carbon::createFromTimestampMs((int) $value) : Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }

        return $c->setTimezone(config('app.timezone'));
    }

    private function participantEntry(RouteAssignment $a, string $key): array
    {
        foreach ((array) ($a->participants ?? []) as $entry) {
            if ((string) ($entry['id'] ?? '') === $key) {
                return (array) $entry;
            }
        }

        return [];
    }

    /** Namen van wie posities en logs kan zien — zo staat het ook letterlijk in de toestemming. */
    private function viewersOf(RouteAssignment $a): array
    {
        $names = [trim((string) $a->owner?->full_name)];
        $editors = RouteAssignmentCollaborator::where('route_assignment_id', $a->id)
            ->where('status', 'accepted')->where('role', 'editor')->with('user:id,first_name,last_name,email')->get();
        foreach ($editors as $c) {
            $names[] = trim((string) $c->user?->full_name);
        }

        return array_values(array_filter(array_unique($names)));
    }

    /** Mag er nu een positie worden gedeeld? [actief, reden-als-niet]. */
    private function sharingState(KakoAppParticipant $p, array $cfg): array
    {
        if ($cfg['tracking']['mode'] === 'off') {
            return [false, 'tracking_off'];
        }
        if ($p->consent_withdrawn_at) {
            return [false, 'withdrawn'];
        }
        if (! $p->hasConsent(Rules::CONSENT_VERSION)) {
            return [false, 'no_consent'];
        }
        if (! $p->window_from || ! $p->window_until || now()->lt($p->window_from)) {
            return [false, 'not_started'];
        }
        if (now()->gt($p->window_until)) {
            return [false, 'ended'];
        }

        return [true, null];
    }

    private function summary(KakoAppParticipant $p, RouteAssignment $a): array
    {
        $cfg = Rules::config($a->settings ?? []);
        [$sharing, $reason] = $this->sharingState($p, $cfg);
        $entry = $this->participantEntry($a, $p->participant_key);

        return [
            'assignment_id' => $a->id,
            'title' => $a->title,
            'type' => $a->type,
            'participant_key' => $p->participant_key,
            'name' => $p->display_name,
            'route_code' => $entry['route_code'] ?? null,
            'status' => $p->status,
            'window' => ['from' => $this->iso($p->window_from), 'until' => $this->iso($p->window_until)],
            'active' => $p->inWindow(),
            'ended' => $p->window_until ? now()->gt($p->window_until) : false,
            'app_mode' => [
                'tracking' => $cfg['tracking'],
                'checkin' => $cfg['checkin'],
                'leave_allowed' => $cfg['leave_allowed'],
            ],
            // true = toegestaan, false = geblokkeerd binnen het venster.
            'restrictions' => Rules::restrictions($a->settings ?? []),
            'consent' => [
                'required_version' => Rules::CONSENT_VERSION,
                'given' => $p->hasConsent(Rules::CONSENT_VERSION),
                'at' => $this->iso($p->consent_at),
                'withdrawn_at' => $this->iso($p->consent_withdrawn_at),
            ],
            'sharing' => ['active' => $sharing, 'reason' => $reason],
            'mode_left' => $p->mode_left_at !== null,
            'seen_by' => $this->viewersOf($a),
            'server_time' => $this->iso(now()),
        ];
    }

    // ── Aansluiten (deelnemer) ────────────────────────────────────────

    private function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    private function openInvite(string $rawCode): array
    {
        $code = $this->normalizeCode($rawCode);
        $p = $code === '' ? null : KakoAppParticipant::where('invite_code', $code)->where('status', 'invited')->first();
        // Eén en dezelfde melding voor "bestaat niet" en "al gebruikt": geen code-orakel.
        abort_unless($p, 422, 'Deze code is ongeldig of al gebruikt.');
        abort_if($p->invite_expires_at && $p->invite_expires_at->isPast(), 422, 'Deze code is verlopen. Vraag de instructeur om een nieuwe.');
        $a = RouteAssignment::find($p->route_assignment_id);
        abort_unless($a, 422, 'Deze code is ongeldig of al gebruikt.');
        abort_if(($a->settings['mode'] ?? 'paper') === 'paper', 422, 'Deze opdracht wordt op papier gelopen.');

        return [$p, $a];
    }

    /** GET /kako/join/{code} — wie ben je als je deze code gebruikt? (zonder te claimen) */
    public function joinPreview(string $code): JsonResponse
    {
        [$p, $a] = $this->openInvite($code);

        return response()->json([
            'title' => $a->title,
            'name' => $p->display_name,
            'expires_at' => $this->iso($p->invite_expires_at),
        ]);
    }

    /** POST /kako/join { code } */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        [$p, $a] = $this->openInvite($data['code']);

        $other = KakoAppParticipant::where('route_assignment_id', $a->id)->where('user_id', auth()->id())->where('id', '!=', $p->id)->first();
        abort_if($other, 409, 'Je neemt al deel aan deze opdracht onder een andere naam.');

        // Atomair claimen: twee gelijktijdige aanroepen met dezelfde code kunnen niet allebei slagen.
        $claimed = KakoAppParticipant::where('id', $p->id)->where('status', 'invited')->update([
            'user_id' => auth()->id(),
            'status' => 'accepted',
            'accepted_at' => now(),
            'invite_code' => null,
            'invite_expires_at' => null,
            'last_seen_at' => now(),
        ]);
        abort_unless($claimed === 1, 422, 'Deze code is ongeldig of al gebruikt.');
        $p = $p->fresh();
        $this->logEvent($a->id, $p, 'joined');

        return response()->json($this->summary($p->fresh(), $a), 201);
    }

    /** GET /kako/participations — mijn deelnames (voor de modusstore en het overzicht). */
    public function participations(): JsonResponse
    {
        $rows = KakoAppParticipant::where('user_id', auth()->id())->where('status', 'accepted')
            ->where(fn ($q) => $q->whereNull('window_until')->orWhere('window_until', '>', now()->subDays(7)))
            ->orderByDesc('window_from')->orderByDesc('id')->limit(50)->get();
        $assignments = RouteAssignment::whereIn('id', $rows->pluck('route_assignment_id'))->get()->keyBy('id');

        return response()->json(
            $rows->filter(fn ($p) => $assignments->has($p->route_assignment_id))
                ->map(fn ($p) => $this->summary($p, $assignments[$p->route_assignment_id]))->values()
        );
    }

    // ── Eigen route ───────────────────────────────────────────────────

    /**
     * De route(delen) van één deelnemer. `targets` is de lijst prikkers waar een check-in
     * geldig is (incl. start/finish), met coördinaten — alleen voor intern gebruik, nooit in een antwoord.
     */
    private function routeOf(RouteAssignment $a, KakoAppParticipant $p, ?Collection $routes = null): array
    {
        $entry = $this->participantEntry($a, $p->participant_key);
        $parts = Rules::routeParts($entry['route_code'] ?? null, $entry['order'] ?? null);
        $routes ??= RouteMap::where('route_assignment_id', $a->id)->get();
        $byCode = $routes->keyBy(fn ($r) => strtoupper((string) $r->route_code));
        $withDescription = ($a->settings['print']['showDescription'] ?? true) !== false;

        $out = [];
        $targets = [];
        foreach ($parts as $index => $part) {
            $route = $byCode->get(strtoupper($part['code']));
            $locations = $route ? array_values((array) $route->locations) : [];
            $legs = Rules::legs($locations, $part['kind'], $withDescription);
            // De laatste leg van een niet-laatste deel is het wisselpunt naar het volgende deel, de eindstreep is alleen de laatste.
            if ($index < count($parts) - 1 && $legs) {
                $legs[count($legs) - 1]['role'] = 'post';
                $legs[count($legs) - 1]['label'] = 'Wissel';
            }
            $out[] = [
                'code' => $part['code'],
                'kind' => $part['kind'],
                'found' => $route !== null,
                'legs' => $legs,
                'total_distance_m' => (int) array_sum(array_column($legs, 'distance_m')),
            ];
            foreach ($locations as $i => $loc) {
                if (! isset($loc['id'], $loc['lat'], $loc['lon'])) {
                    continue;
                }
                $id = (string) $loc['id'];
                $isEdge = $i === 0 || $i === count($locations) - 1;
                $targets[$id] ??= [
                    'label' => (string) ($loc['checkpoint']['label'] ?? $loc['title'] ?? $id),
                    'lat' => (float) $loc['lat'],
                    'lon' => (float) $loc['lon'],
                    'edge' => false,
                ];
                $targets[$id]['edge'] = $targets[$id]['edge'] || $isEdge;
            }
        }

        return ['code' => $entry['route_code'] ?? null, 'order' => $entry['order'] ?? null, 'parts' => $out, 'targets' => $targets];
    }

    /** Per (prikker, rol): aantal pogingen, bezocht, eerste geslaagde tijd. */
    private function checkinStatus(KakoAppParticipant $p): array
    {
        $status = [];
        foreach (KakoAppCheckin::where('participant_id', $p->id)->orderBy('attempted_at')->get() as $c) {
            $k = $c->post_id . '|' . $c->role;
            $status[$k] ??= ['post_id' => $c->post_id, 'role' => $c->role, 'attempts' => 0, 'visited' => false, 'visited_at' => null, 'last_result' => null];
            $status[$k]['attempts']++;
            $status[$k]['last_result'] = $c->result;
            if ($c->result === 'ok' && ! $status[$k]['visited']) {
                $status[$k]['visited'] = true;
                $status[$k]['visited_at'] = $this->iso($c->attempted_at);
            }
        }

        return $status;
    }

    /** De volledige deelnemer-payload (samenvatting + eigen route met check-in-stand per prikker). */
    private function payloadFor(RouteAssignment $a, KakoAppParticipant $p): array
    {
        $route = $this->routeOf($a, $p);
        unset($route['targets']);
        $status = $this->checkinStatus($p);
        foreach ($route['parts'] as &$part) {
            foreach ($part['legs'] as &$leg) {
                $s = $status[$leg['post_id'] . '|' . $leg['role']] ?? null;
                $leg['attempts'] = $s['attempts'] ?? 0;
                $leg['visited'] = $s['visited'] ?? false;
                $leg['visited_at'] = $s['visited_at'] ?? null;
                $leg['last_result'] = $s['last_result'] ?? null;
            }
            unset($leg);
        }
        unset($part);

        return $this->summary($p, $a) + [
            'route' => $route,
            'emergency' => $a->settings['emergency'] ?? null,
        ];
    }

    /** GET /route-assignments/{id}/participation — mijn payload. */
    public function participation(string $id): JsonResponse
    {
        $a = $this->assignment($id);
        $p = $this->participantOf($a, ['accepted', 'declined']);
        $p->forceFill(['last_seen_at' => now()])->save();

        return response()->json($this->payloadFor($a, $p));
    }

    /**
     * GET /route-assignments/{id}/participants/{key}/preview — meekijken (alleen beheerder): precies wat deze
     * deelnemer in de app ziet, ook voor iemand die nog niet is aangesloten. De route volgt altijd de HUIDIGE
     * `route_code`. Schrijft niets (geen laatst-gezien, geen gebeurtenis): het is dezelfde data die de
     * instructeur al heeft.
     */
    public function preview(string $id, string $key): JsonResponse
    {
        $a = $this->manager($id);
        $entry = $this->participantEntry($a, $key);
        abort_if($entry === [], 404, 'Deze deelnemer staat niet in de training');

        $p = KakoAppParticipant::where('route_assignment_id', $a->id)->where('participant_key', $key)->first()
            ?? new KakoAppParticipant([
                'route_assignment_id' => $a->id,
                'participant_key' => $key,
                'display_name' => (string) ($entry['name'] ?? '?'),
                'status' => 'accepted',
            ]);

        return response()->json($this->payloadFor($a, $p) + ['preview' => true, 'joined' => $p->exists]);
    }

    // ── Toestemming (positie delen) ───────────────────────────────────

    /** POST /route-assignments/{id}/participation/consent { tracking, version } */
    public function consent(Request $request, string $id): JsonResponse
    {
        $a = $this->assignment($id);
        $p = $this->participantOf($a, ['accepted', 'declined']);
        $cfg = Rules::config($a->settings ?? []);
        $data = $request->validate(['tracking' => ['required', 'boolean'], 'version' => ['required', 'integer']]);
        abort_if((int) $data['version'] !== Rules::CONSENT_VERSION, 422, 'De toestemmingstekst is gewijzigd. Lees hem opnieuw en bevestig.');

        if ($data['tracking']) {
            abort_if($cfg['tracking']['mode'] === 'off', 422, 'Positie delen staat uit voor deze opdracht.');
            $p->update(['consent_version' => Rules::CONSENT_VERSION, 'consent_at' => now(), 'consent_withdrawn_at' => null, 'status' => 'accepted']);
            $this->logEvent($a->id, $p, 'consent_given', ['version' => Rules::CONSENT_VERSION]);
        } else {
            // Weigeren: bij 'verplicht' loopt de deelnemer op papier, anders gewoon zonder positie.
            // Ook bij 'optioneel' leggen we de keuze vast (als "geen toestemming"), zodat de app niet blijft vragen.
            $p->update([
                'consent_at' => null, 'consent_withdrawn_at' => now(),
                'status' => $cfg['tracking']['mode'] === 'required' ? 'declined' : 'accepted',
                'last_lat' => null, 'last_lon' => null, 'last_accuracy' => null, 'last_position_at' => null,
            ]);
            $this->logEvent($a->id, $p, 'consent_declined', ['required' => $cfg['tracking']['mode'] === 'required']);
        }

        return response()->json($this->summary($p->fresh(), $a));
    }

    /** DELETE /route-assignments/{id}/participation/consent[?delete_data=1] — intrekken, stopt direct. */
    public function withdraw(Request $request, string $id): JsonResponse
    {
        $a = $this->assignment($id);
        $p = $this->participantOf($a);

        $p->update(['consent_withdrawn_at' => now(), 'last_lat' => null, 'last_lon' => null, 'last_accuracy' => null, 'last_position_at' => null]);
        $this->logEvent($a->id, $p, 'consent_withdrawn');
        if ($request->boolean('delete_data')) {
            $this->wipePositions($a, $p);
        }

        return response()->json($this->summary($p->fresh(), $a));
    }

    private function wipePositions(RouteAssignment $a, KakoAppParticipant $p): int
    {
        $n = KakoAppPosition::where('participant_id', $p->id)->delete();
        $p->update(['last_lat' => null, 'last_lon' => null, 'last_accuracy' => null, 'last_position_at' => null]);
        $this->logEvent($a->id, $p, 'positions_deleted', ['count' => $n]);

        return $n;
    }

    /** DELETE /route-assignments/{id}/participation/positions — mijn posities wissen (AVG art. 17). */
    public function deleteMyPositions(string $id): JsonResponse
    {
        $a = $this->assignment($id);
        $p = $this->participantOf($a);

        return response()->json(['deleted' => $this->wipePositions($a, $p)]);
    }

    /** GET /route-assignments/{id}/participation/my-data — inzage-export (AVG art. 15). */
    public function myData(string $id): JsonResponse
    {
        $a = $this->assignment($id);
        $p = $this->participantOf($a);
        $this->logEvent($a->id, $p, 'data_exported');

        return response()->json([
            'participation' => $this->summary($p, $a),
            'checkins' => KakoAppCheckin::where('participant_id', $p->id)->orderBy('attempted_at')->get()
                ->map(fn ($c) => $this->presentCheckin($c, withPosition: true))->values(),
            'positions' => KakoAppPosition::where('participant_id', $p->id)->orderBy('recorded_at')->limit(50000)->get()
                ->map(fn ($s) => $this->presentSample($s))->values(),
            // Eigen gebeurtenissen + "iemand bekeek de live-kaart van de hele groep" (hoort ook bij jou).
            'events' => KakoAppEvent::where('route_assignment_id', $a->id)
                ->where(fn ($q) => $q->where('participant_id', $p->id)->orWhere(fn ($q) => $q->whereNull('participant_id')->where('type', 'live_viewed')))
                ->orderBy('id')->get()
                ->map(fn ($e) => ['type' => $e->type, 'by' => $e->actor_name, 'data' => $e->data, 'at' => $this->iso($e->created_at)])->values(),
        ]);
    }

    /** POST /route-assignments/{id}/participation/mode { state: left|resumed } */
    public function mode(Request $request, string $id): JsonResponse
    {
        $a = $this->assignment($id);
        $p = $this->participantOf($a);
        $data = $request->validate(['state' => ['required', 'in:left,resumed']]);
        $cfg = Rules::config($a->settings ?? []);

        if ($data['state'] === 'left') {
            abort_unless($cfg['leave_allowed'], 403, 'De instructeur heeft ingesteld dat je de afgeschermde modus niet mag verlaten.');
            if (! $p->mode_left_at) {
                $p->update(['mode_left_at' => now()]);
                $this->logEvent($a->id, $p, 'mode_left');
            }
        } elseif ($p->mode_left_at) {
            $p->update(['mode_left_at' => null]);
            $this->logEvent($a->id, $p, 'mode_resumed');
        }

        return response()->json($this->summary($p->fresh(), $a));
    }

    // ── Check-in ──────────────────────────────────────────────────────

    /**
     * `late_s` = hoeveel seconden NA de cut off tijd (venster-einde incl. extra tijd, zoals die op het moment van de
     * poging gold) deze poging was; null als hij op tijd was. Zo is een check-in na de cut off als "te laat" te
     * herkennen, ook als hij als `outside_window` niet meetelt. De grens is een momentopname: later verlengen of
     * inkorten van het venster verandert het log niet.
     */
    private function presentCheckin(KakoAppCheckin $c, bool $withPosition = false): array
    {
        $late = $c->deadline_at && $c->attempted_at->gt($c->deadline_at) ? (int) abs($c->attempted_at->diffInSeconds($c->deadline_at)) : null;

        return [
            'id' => $c->id,
            'client_uuid' => $c->client_uuid,
            'post_id' => $c->post_id,
            'post_label' => $c->post_label,
            'role' => $c->role,
            'method' => $c->method,
            'result' => $c->result,
            'attempted_at' => $this->iso($c->attempted_at),
            'received_at' => $this->iso($c->received_at),
            'distance_m' => $c->distance_m !== null ? round($c->distance_m, 1) : null,
            'within_radius' => $c->within_radius,
            'late_s' => $late,
        ] + ($withPosition ? ['lat' => $c->lat, 'lon' => $c->lon, 'accuracy' => $c->accuracy] : []);
    }

    /** POST /route-assignments/{id}/checkins */
    public function checkin(Request $request, string $id): JsonResponse
    {
        $a = $this->assignment($id);
        $p = $this->participantOf($a);

        $request->merge(GpsValues::normalize($request->only(['accuracy'])));
        $data = $request->validate([
            'client_uuid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'post_id' => ['required', 'string', 'max:64'],
            'role' => ['sometimes', 'nullable', 'in:post,start,finish'],
            'method' => ['sometimes', 'in:manual,qr,nfc'],
            'attempted_at' => ['sometimes', 'nullable'],
            'fix_at' => ['sometimes', 'nullable'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lon' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'code' => ['nullable', 'string', 'max:128'],
            'device' => ['nullable', 'string', 'max:64'],
        ]);

        // Een herhaling (outbox, dubbele tik) geeft dezelfde rij terug i.p.v. een nieuwe poging.
        $existing = KakoAppCheckin::where('participant_id', $p->id)->where('client_uuid', $data['client_uuid'])->first();
        if ($existing) {
            return response()->json(['checkin' => $this->presentCheckin($existing), 'duplicate' => true]);
        }

        $cfg = Rules::config($a->settings ?? []);
        $received = now();
        // GPS-tijd verdient de voorkeur boven de apparaatklok; nooit in de toekomst of ouder dan een dag.
        $attempted = $this->ts($data['fix_at'] ?? null) ?? $this->ts($data['attempted_at'] ?? null) ?? $received->copy();
        if ($attempted->gt($received->copy()->addMinute()) || $attempted->lt($received->copy()->subHours(Rules::LATE_UPLOAD_HOURS))) {
            $attempted = $received->copy();
        }

        $route = $this->routeOf($a, $p);
        $target = $route['targets'][$data['post_id']] ?? null;
        $role = $data['role'] ?? ($target && $target['edge'] ? 'start' : 'post');
        $method = $data['method'] ?? 'manual';
        $hasPosition = isset($data['lat'], $data['lon']);

        $distance = $target && $hasPosition ? Rules::distanceM((float) $data['lat'], (float) $data['lon'], $target['lat'], $target['lon']) : null;
        $within = $distance !== null ? Rules::withinRadius($distance, $data['accuracy'] ?? null, $cfg['checkin']['radius_m']) : null;

        $result = $this->checkinResult($a, $p, $cfg, $target, $method, $data, $attempted, $role, $hasPosition, $within);

        $label = $target ? ($role === 'finish' ? 'Finish' : ($role === 'start' && $target['edge'] ? 'Start' : $target['label'])) : null;
        try {
            $checkin = KakoAppCheckin::create([
                'route_assignment_id' => $a->id,
                'participant_id' => $p->id,
                'user_id' => auth()->id(),
                'client_uuid' => $data['client_uuid'],
                'post_id' => $data['post_id'],
                'post_label' => $label ? mb_substr($label, 0, 64) : null,
                'role' => $role,
                'method' => $method,
                'attempted_at' => $attempted,
                'received_at' => $received,
                'deadline_at' => $p->window_until,
                'lat' => $data['lat'] ?? null,
                'lon' => $data['lon'] ?? null,
                'accuracy' => $data['accuracy'] ?? null,
                'distance_m' => $distance,
                'within_radius' => $within,
                'result' => $result,
                'device' => $data['device'] ?? null,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Dezelfde client_uuid kwam gelijktijdig binnen (outbox + dubbele tik): de eerste wint, dit is de herhaling.
            $existing = KakoAppCheckin::where('participant_id', $p->id)->where('client_uuid', $data['client_uuid'])->firstOrFail();

            return response()->json(['checkin' => $this->presentCheckin($existing), 'duplicate' => true]);
        }
        $p->forceFill(['last_seen_at' => now()])->save();

        $attempts = KakoAppCheckin::where('participant_id', $p->id)->where('post_id', $data['post_id'])->where('role', $role)->count();
        $visited = KakoAppCheckin::where('participant_id', $p->id)->where('post_id', $data['post_id'])->where('role', $role)->where('result', 'ok')->exists();

        return response()->json([
            'checkin' => $this->presentCheckin($checkin),
            'status' => ['post_id' => $data['post_id'], 'role' => $role, 'attempts' => $attempts, 'visited' => $visited],
        ], 201);
    }

    /** Bepaalt de uitkomst van één poging. Volgorde = wat het eerst misgaat. */
    private function checkinResult(RouteAssignment $a, KakoAppParticipant $p, array $cfg, ?array $target, string $method, array $data, Carbon $attempted, string $role, bool $hasPosition, ?bool $within): string
    {
        if (! $target) {
            return 'not_on_route';
        }
        if (! $p->inWindow($attempted)) {
            return 'outside_window';
        }
        if (! in_array($method, $cfg['checkin']['methods'], true)) {
            return 'method_not_allowed';
        }
        $max = $cfg['checkin']['max_attempts'];
        if ($max !== null) {
            $base = KakoAppCheckin::where('participant_id', $p->id)->where('post_id', $data['post_id'])->where('role', $role);
            $already = (clone $base)->where('result', 'ok')->exists();
            $tries = (clone $base)->whereIn('result', ['ok', 'too_far', 'no_position', 'wrong_code'])->count();
            if (! $already && $tries >= $max) {
                return 'attempts_exhausted';
            }
        }
        if ($method === 'qr' || $method === 'nfc') {
            // Een geldig token bewijst aanwezigheid bij de code; GPS is dan niet nodig.
            return Rules::tagValid($a->id, $data['post_id'], $cfg['tag_version'], $data['code'] ?? null) ? 'ok' : 'wrong_code';
        }
        if (! $hasPosition) {
            return 'no_position';
        }
        if ($cfg['checkin']['verification'] === 'record') {
            return 'ok';
        }

        return $within ? 'ok' : 'too_far';
    }

    /** GET /route-assignments/{id}/checkins/mine */
    public function myCheckins(string $id): JsonResponse
    {
        $a = $this->assignment($id);
        $p = $this->participantOf($a);

        return response()->json([
            'checkins' => KakoAppCheckin::where('participant_id', $p->id)->orderByDesc('attempted_at')->limit(300)->get()
                ->map(fn ($c) => $this->presentCheckin($c))->values(),
            'status' => array_values($this->checkinStatus($p)),
        ]);
    }

    // ── Positie-samples ───────────────────────────────────────────────

    private function presentSample(KakoAppPosition $s): array
    {
        return ['t' => $this->iso($s->recorded_at), 'lat' => $s->lat, 'lon' => $s->lon, 'accuracy' => $s->accuracy, 'speed' => $s->speed, 'heading' => $s->heading];
    }

    /**
     * POST /route-assignments/{id}/positions { samples: [{t, lat, lon, accuracy?, speed?, heading?}] }
     * Altijd 200: een afgewezen batch is geen fout maar een signaal (`sharing`) waarop de app z'n watcher stopt.
     */
    public function positions(Request $request, string $id): JsonResponse
    {
        $a = $this->assignment($id);
        $p = $this->participantOf($a);
        $cfg = Rules::config($a->settings ?? []);

        $data = $request->validate([
            'samples' => ['required', 'array', 'min:1', 'max:' . self::MAX_SAMPLES],
            'samples.*.t' => ['required'],
            'samples.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'samples.*.lon' => ['required', 'numeric', 'between:-180,180'],
            'samples.*.accuracy' => ['nullable', 'numeric'],
            'samples.*.speed' => ['nullable', 'numeric'],
            'samples.*.heading' => ['nullable', 'numeric'],
        ]);
        $count = count($data['samples']);
        $p->forceFill(['last_seen_at' => now()])->save();

        [$active, $reason] = $this->sharingState($p, $cfg);
        // Alleen toestemming + venster tellen. 'ended' mag nog: offline gebufferde samples van binnen het venster.
        $lateOk = $reason === 'ended' && now()->lte($p->window_until->copy()->addHours(Rules::LATE_UPLOAD_HOURS));
        if (! $active && ! $lateOk) {
            return response()->json(['accepted' => 0, 'duplicate' => 0, 'rejected' => $count, 'sharing' => ['active' => false, 'reason' => $reason]]);
        }

        $rows = [];
        $rejected = 0;
        $newest = null;
        foreach ($data['samples'] as $s) {
            $t = $this->ts($s['t']);
            $norm = GpsValues::normalize(['accuracy' => $s['accuracy'] ?? null, 'speed' => $s['speed'] ?? null, 'heading' => $s['heading'] ?? null]);
            if (! $t || ! $p->inWindow($t) || $t->gt(now()->addMinute())) {
                $rejected++;
                continue;
            }
            $key = $t->format('Y-m-d H:i:s.v');
            $rows[$key] = [
                'route_assignment_id' => $a->id,
                'participant_id' => $p->id,
                'user_id' => auth()->id(),
                'recorded_at' => $key,
                'lat' => round((float) $s['lat'], 7),
                'lon' => round((float) $s['lon'], 7),
                'accuracy' => $norm['accuracy'],
                'speed' => $norm['speed'],
                'heading' => $norm['heading'],
            ];
            if (! $newest || $t->gt($newest['t'])) {
                $newest = ['t' => $t, 'row' => $rows[$key]];
            }
        }

        $inserted = $rows ? KakoAppPosition::insertOrIgnore(array_values($rows)) : 0;
        if ($newest && (! $p->last_position_at || $newest['t']->gt($p->last_position_at))) {
            $p->forceFill([
                'last_lat' => $newest['row']['lat'], 'last_lon' => $newest['row']['lon'],
                'last_accuracy' => $newest['row']['accuracy'], 'last_position_at' => $newest['t'],
            ])->save();
        }

        return response()->json([
            'accepted' => $inserted,
            'duplicate' => count($rows) - $inserted,
            'rejected' => $rejected,
            'sharing' => ['active' => $active, 'reason' => $reason],
        ]);
    }

    // ── Beheerder: deelnemers, uitnodigen, venster ────────────────────

    private function newInviteCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= self::INVITE_ALPHABET[random_int(0, strlen(self::INVITE_ALPHABET) - 1)];
            }
        } while (KakoAppParticipant::where('invite_code', $code)->exists());

        return $code;
    }

    private function presentParticipant(RouteAssignment $a, array $entry, ?KakoAppParticipant $p, ?Collection $routes, ?Collection $users, ?Map $map, Collection $counts): array
    {
        $key = (string) ($entry['id'] ?? '');
        $base = ['key' => $key, 'name' => $entry['name'] ?? '', 'route_code' => $entry['route_code'] ?? null];
        if (! $p) {
            return $base + ['status' => 'none'];
        }
        $cfg = Rules::config($a->settings ?? []);
        [$sharing, $reason] = $this->sharingState($p, $cfg);
        $user = $p->user_id ? $users?->get($p->user_id) : null;
        $visited = (int) ($counts['visited'][$p->id] ?? 0);
        $targets = $routes ? collect($this->routeOf($a, $p, $routes)['parts'])->flatMap(fn ($part) => $part['legs'])->unique(fn ($l) => $l['post_id'] . '|' . $l['role'])->count() : null;

        return $base + [
            'status' => $p->status,
            'user' => $user ? ['id' => $user->id, 'name' => trim((string) $user->full_name)] : null,
            // Wie de kaart mag zien, kan alle posten en routes zien: bij een examen ongewenst.
            'has_map_access' => $user && $map ? Gate::forUser($user)->allows('view', $map) : false,
            'consent' => ['given' => $p->hasConsent(Rules::CONSENT_VERSION), 'at' => $this->iso($p->consent_at), 'withdrawn_at' => $this->iso($p->consent_withdrawn_at)],
            'window' => ['from' => $this->iso($p->window_from), 'until' => $this->iso($p->window_until)],
            'in_window' => $p->inWindow(),
            'sharing' => ['active' => $sharing, 'reason' => $reason],
            'mode_left' => $p->mode_left_at !== null,
            'last' => $p->last_position_at ? [
                'lat' => $p->last_lat, 'lon' => $p->last_lon, 'accuracy' => $p->last_accuracy,
                'at' => $this->iso($p->last_position_at), 'age_s' => (int) abs($p->last_position_at->diffInSeconds(now())),
            ] : null,
            'last_seen_at' => $this->iso($p->last_seen_at),
            'checkins' => ['attempts' => (int) ($counts['attempts'][$p->id] ?? 0), 'posts_visited' => $visited, 'posts_total' => $targets],
            'invite' => $p->status === 'invited' && $p->invite_code ? [
                'code' => $p->invite_code,
                'expires_at' => $this->iso($p->invite_expires_at),
                'join_path' => '/kako/deelnemen/' . $p->invite_code,
            ] : null,
        ];
    }

    private function presentParticipants(RouteAssignment $a): array
    {
        $rows = KakoAppParticipant::where('route_assignment_id', $a->id)->get()->keyBy('participant_key');
        $routes = RouteMap::where('route_assignment_id', $a->id)->get();
        $users = User::whereIn('id', $rows->pluck('user_id')->filter())->get()->keyBy('id');
        $map = Map::withoutGlobalScopes()->find($a->map_id);
        $counts = [
            'attempts' => KakoAppCheckin::where('route_assignment_id', $a->id)->select('participant_id', DB::raw('count(*) as n'))->groupBy('participant_id')->pluck('n', 'participant_id'),
            // Uniek (prikker, rol) per deelnemer; in PHP geteld zodat er geen database-specifieke concat nodig is.
            'visited' => KakoAppCheckin::where('route_assignment_id', $a->id)->where('result', 'ok')
                ->select('participant_id', 'post_id', 'role')->distinct()->get()->groupBy('participant_id')->map->count(),
        ];

        return collect((array) ($a->participants ?? []))
            ->map(fn ($entry) => $this->presentParticipant($a, (array) $entry, $rows->get((string) ($entry['id'] ?? '')), $routes, $users, $map, collect($counts)))
            ->values()->all();
    }

    /** GET /route-assignments/{id}/participants */
    public function participants(string $id): JsonResponse
    {
        $a = $this->manager($id);

        return response()->json(['participants' => $this->presentParticipants($a), 'server_time' => $this->iso(now())]);
    }

    /** POST /route-assignments/{id}/participants/invites { participant_keys?: [] } */
    public function invite(Request $request, string $id): JsonResponse
    {
        $a = $this->manager($id);
        abort_if(($a->settings['mode'] ?? 'paper') === 'paper', 422, "Zet de uitvoering op 'Met de app' of 'Allebei' voordat je deelnemers uitnodigt.");
        $data = $request->validate(['participant_keys' => ['sometimes', 'array', 'max:500'], 'participant_keys.*' => ['string', 'max:64']]);
        $only = isset($data['participant_keys']) ? array_flip($data['participant_keys']) : null;

        $created = 0;
        DB::transaction(function () use ($a, $only, &$created) {
            foreach ((array) ($a->participants ?? []) as $entry) {
                $key = (string) ($entry['id'] ?? '');
                if ($key === '' || ($only !== null && ! isset($only[$key]))) {
                    continue;
                }
                $row = KakoAppParticipant::where('route_assignment_id', $a->id)->where('participant_key', $key)->first();
                if ($row && $row->status === 'accepted') {
                    continue; // al gekoppeld: niets te doen
                }
                $fresh = [
                    'display_name' => mb_substr((string) ($entry['name'] ?? '?'), 0, 120),
                    'status' => 'invited',
                    'invite_code' => $this->newInviteCode(),
                    'invite_expires_at' => now()->addDays(self::INVITE_DAYS),
                    // Een nieuwe uitnodiging begint schoon: toestemming is persoonlijk en gaat niet mee.
                    'user_id' => null, 'accepted_at' => null,
                    'consent_version' => null, 'consent_at' => null, 'consent_withdrawn_at' => null, 'mode_left_at' => null,
                ];
                $row = $row ? tap($row)->update($fresh) : KakoAppParticipant::create($fresh + ['route_assignment_id' => $a->id, 'participant_key' => $key]);
                $this->logEvent($a->id, $row, 'invite_created');
                $created++;
            }
        });

        return response()->json(['created' => $created, 'participants' => $this->presentParticipants($a)], 201);
    }

    /** DELETE /route-assignments/{id}/participants/{key} */
    public function revoke(string $id, string $key): JsonResponse
    {
        $a = $this->manager($id);
        $p = KakoAppParticipant::where('route_assignment_id', $a->id)->where('participant_key', $key)->firstOrFail();
        $p->update([
            'status' => 'revoked', 'invite_code' => null, 'invite_expires_at' => null,
            'last_lat' => null, 'last_lon' => null, 'last_accuracy' => null, 'last_position_at' => null,
        ]);
        $this->logEvent($a->id, $p, 'revoked');

        return response()->json(['message' => 'Deelnemer ingetrokken']);
    }

    private function bewerkLog(RouteAssignment $a, string $action, string $summary): void
    {
        $u = auth()->user();
        RouteAssignmentLog::create([
            'route_assignment_id' => $a->id,
            'user_id' => $u?->id,
            'user_name' => $u ? $u->full_name : 'Systeem',
            'action' => $action,
            'summary' => mb_substr($summary, 0, 500),
            'created_at' => now(),
        ]);
    }

    /**
     * PUT /route-assignments/{id}/app-mode/window { from, until, participant_keys? }
     * Runtime-staat, dus ook in productie te wijzigen (niet vergrendeld) — wel gelogd.
     */
    public function setWindow(Request $request, string $id): JsonResponse
    {
        $a = $this->manager($id);
        $data = $request->validate([
            'from' => ['required'], 'until' => ['required'],
            'participant_keys' => ['sometimes', 'array', 'max:500'], 'participant_keys.*' => ['string', 'max:64'],
        ]);
        $from = $this->ts($data['from']);
        $until = $this->ts($data['until']);
        abort_unless($from && $until, 422, 'Ongeldige begin- of eindtijd.');
        abort_if($until->lte($from), 422, 'Het einde moet na het begin liggen.');
        abort_if($from->diffInHours($until, true) > Rules::MAX_WINDOW_HOURS, 422, 'Een venster duurt hooguit ' . Rules::MAX_WINDOW_HOURS . ' uur.');

        $rows = KakoAppParticipant::where('route_assignment_id', $a->id)->whereIn('status', ['invited', 'accepted'])
            ->when(isset($data['participant_keys']), fn ($q) => $q->whereIn('participant_key', $data['participant_keys']))->get();
        foreach ($rows as $row) {
            $extended = $row->window_until && $until->gt($row->window_until);
            $row->update(['window_from' => $from, 'window_until' => $until]);
            $this->logEvent($a->id, $row, $extended ? 'window_extended' : 'window_set', ['from' => $this->iso($from), 'until' => $this->iso($until)]);
        }
        $this->bewerkLog($a, 'app_window', sprintf('Deelname-venster voor %d deelnemer(s): %s – %s', $rows->count(), $from->format('d-m H:i'), $until->format('d-m H:i')));

        return response()->json(['updated' => $rows->count(), 'window' => ['from' => $this->iso($from), 'until' => $this->iso($until)]]);
    }

    /** POST /route-assignments/{id}/app-mode/end — nu beëindigen: delen stopt overal. */
    public function endWindow(string $id): JsonResponse
    {
        $a = $this->manager($id);
        $rows = KakoAppParticipant::where('route_assignment_id', $a->id)->whereNotNull('window_until')->where('window_until', '>', now())->get();
        foreach ($rows as $row) {
            // Nog niet begonnen: venster weghalen; bezig: nu afsluiten.
            $row->update($row->window_from && $row->window_from->gt(now()) ? ['window_from' => null, 'window_until' => null] : ['window_until' => now()]);
            $this->logEvent($a->id, $row, 'window_ended');
        }
        $this->bewerkLog($a, 'app_window', sprintf('Deelname beëindigd voor %d deelnemer(s)', $rows->count()));

        return response()->json(['ended' => $rows->count()]);
    }

    // ── Beheerder: live, check-ins, tracks ────────────────────────────

    /** GET /route-assignments/{id}/live */
    public function live(string $id): JsonResponse
    {
        $a = $this->manager($id);
        $this->logAccess($a, null, 'live_viewed');
        $cfg = Rules::config($a->settings ?? []);

        $rows = KakoAppParticipant::where('route_assignment_id', $a->id)->where('status', 'accepted')->get()->map(function ($p) use ($cfg) {
            [$sharing, $reason] = $this->sharingState($p, $cfg);

            return [
                'key' => $p->participant_key,
                'name' => $p->display_name,
                'in_window' => $p->inWindow(),
                'sharing' => ['active' => $sharing, 'reason' => $reason],
                'mode_left' => $p->mode_left_at !== null,
                'last_seen_at' => $this->iso($p->last_seen_at),
                'last' => $p->last_position_at && ! $p->consent_withdrawn_at ? [
                    'lat' => $p->last_lat, 'lon' => $p->last_lon, 'accuracy' => $p->last_accuracy,
                    'at' => $this->iso($p->last_position_at), 'age_s' => (int) abs($p->last_position_at->diffInSeconds(now())),
                ] : null,
            ];
        })->values();

        return response()->json(['participants' => $rows, 'server_time' => $this->iso(now())]);
    }

    /** GET /route-assignments/{id}/checkins?participant=&post_id=&result= */
    public function checkins(Request $request, string $id): JsonResponse
    {
        $a = $this->manager($id);
        $query = KakoAppCheckin::where('route_assignment_id', $a->id)->with('participant:id,participant_key,display_name');
        if ($request->filled('participant')) {
            $query->whereHas('participant', fn ($q) => $q->where('participant_key', $request->query('participant')));
        }
        if ($request->filled('post_id')) {
            $query->where('post_id', $request->query('post_id'));
        }
        if ($request->filled('result')) {
            $query->where('result', $request->query('result'));
        }

        return response()->json($query->orderByDesc('attempted_at')->limit(1000)->get()->map(fn ($c) => [
            'participant_key' => $c->participant?->participant_key,
            'participant_name' => $c->participant?->display_name,
        ] + $this->presentCheckin($c, withPosition: true))->values());
    }

    /** GET /route-assignments/{id}/checkins/summary — matrix deelnemer × prikker. */
    public function checkinSummary(string $id): JsonResponse
    {
        $a = $this->manager($id);
        $rows = KakoAppCheckin::where('route_assignment_id', $a->id)->with('participant:id,participant_key,display_name')->orderBy('attempted_at')->get();

        $matrix = [];
        foreach ($rows->groupBy('participant_id') as $list) {
            $first = $list->first();
            $cells = [];
            foreach ($list->groupBy(fn ($c) => $c->post_id . '|' . $c->role) as $cell) {
                $c0 = $cell->first();
                $ok = $cell->firstWhere('result', 'ok');
                $cells[] = [
                    'post_id' => $c0->post_id, 'role' => $c0->role, 'label' => $c0->post_label,
                    'attempts' => $cell->count(), 'visited' => $ok !== null, 'visited_at' => $this->iso($ok?->attempted_at),
                    'last_result' => $cell->last()->result,
                ];
            }
            $matrix[] = ['participant_key' => $first->participant?->participant_key, 'name' => $first->participant?->display_name, 'posts' => $cells];
        }

        return response()->json(['participants' => $matrix]);
    }

    /** GET /route-assignments/{id}/positions?participant=KEY&from=&to=&step=SECONDS */
    public function track(Request $request, string $id): JsonResponse
    {
        $a = $this->manager($id);
        $data = $request->validate(['participant' => ['required', 'string', 'max:64'], 'step' => ['sometimes', 'integer', 'min:0', 'max:3600']]);
        $p = KakoAppParticipant::where('route_assignment_id', $a->id)->where('participant_key', $data['participant'])->firstOrFail();
        $this->logAccess($a, $p, 'track_viewed');

        $query = KakoAppPosition::where('participant_id', $p->id)->orderBy('recorded_at');
        if ($from = $this->ts($request->query('from'))) {
            $query->where('recorded_at', '>=', $from);
        }
        if ($to = $this->ts($request->query('to'))) {
            $query->where('recorded_at', '<=', $to);
        }
        $step = (int) ($data['step'] ?? 0);
        $out = [];
        $last = null;
        foreach ($query->limit(20000)->get() as $s) {
            if ($step > 0 && $last !== null && abs($s->recorded_at->diffInSeconds($last)) < $step) {
                continue;
            }
            $out[] = $this->presentSample($s);
            $last = $s->recorded_at;
        }

        return response()->json(['participant_key' => $p->participant_key, 'name' => $p->display_name, 'count' => count($out), 'positions' => $out]);
    }

    /** GET /route-assignments/{id}/participant-events */
    public function events(string $id): JsonResponse
    {
        $a = $this->manager($id);

        return response()->json(
            KakoAppEvent::where('route_assignment_id', $a->id)->orderByDesc('id')->limit(500)->get()->map(function ($e) {
                $p = $e->participant_id ? KakoAppParticipant::find($e->participant_id, ['id', 'participant_key', 'display_name']) : null;

                return ['type' => $e->type, 'participant_key' => $p?->participant_key, 'participant_name' => $p?->display_name, 'by' => $e->actor_name, 'data' => $e->data, 'at' => $this->iso($e->created_at)];
            })->values()
        );
    }

    /** GET /route-assignments/{id}/tag-tokens — QR/NFC-tokens per prikker, voor het printen van de codes (fase 4 UI). */
    public function tagTokens(string $id): JsonResponse
    {
        $a = $this->manager($id);
        $cfg = Rules::config($a->settings ?? []);
        $posts = collect($a->settings['posts'] ?? [])->filter(fn ($p) => isset($p['id']))->map(fn ($p) => [
            'post_id' => (string) $p['id'],
            'label' => (string) ($p['label'] ?? $p['id']),
            'token' => Rules::tagToken($a->id, (string) $p['id'], $cfg['tag_version']),
        ])->values();

        return response()->json(['tag_version' => $cfg['tag_version'], 'posts' => $posts]);
    }
}
