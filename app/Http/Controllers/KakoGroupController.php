<?php

namespace App\Http\Controllers;

use App\Models\KakoGroup;
use App\Services\KakoConnectDevices;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Persoonlijke deelnemersgroepen voor het KAKO-tabblad "Deelnemers", plus de lijst
 * Connect-apparaten waaruit je leden kunt kiezen (alleen voor Connect-beheerders).
 */
class KakoGroupController extends Controller
{
    private const MAX_GROUPS = 100;
    private const MAX_MEMBERS = 500;

    public function index()
    {
        return response()->json(
            KakoGroup::where('user_id', auth()->id())->orderBy('name')->get()->map(fn (KakoGroup $g) => $this->present($g))->values()
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        abort_if(KakoGroup::where('user_id', auth()->id())->count() >= self::MAX_GROUPS, 422, 'Maximaal ' . self::MAX_GROUPS . ' groepen');
        $this->assertUniqueName($data['name']);
        $members = $this->members($data['members'] ?? [], []);

        $group = KakoGroup::create(['user_id' => auth()->id(), 'name' => $data['name'], 'members' => $members]);

        return response()->json($this->present($group), 201);
    }

    public function update(Request $request, int $id)
    {
        $group = KakoGroup::where('user_id', auth()->id())->findOrFail($id);
        $data = $this->validated($request, creating: false);
        if (isset($data['name'])) {
            $this->assertUniqueName($data['name'], $group->id);
        }

        $changes = [];
        if (isset($data['name'])) {
            $changes['name'] = $data['name'];
        }
        if (array_key_exists('members', $data)) {
            $changes['members'] = $this->members($data['members'] ?? [], $group->members ?? []);
        }
        $group->update($changes);

        return response()->json($this->present($group->refresh()));
    }

    public function destroy(int $id)
    {
        KakoGroup::where('user_id', auth()->id())->findOrFail($id)->delete();

        return response()->json(['message' => 'Groep verwijderd']);
    }

    /** GET /kako-connect-devices?q=&organization_id= — leeg voor wie de apparaten in Connect niet mag zien. */
    public function connectDevices(Request $request, KakoConnectDevices $devices)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'organization_id' => ['nullable', 'integer'],
        ]);

        return response()->json($devices->search(auth()->user(), $data['q'] ?? null, isset($data['organization_id']) ? (int) $data['organization_id'] : null));
    }

    private function validated(Request $request, bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:80'],
            'members' => ['sometimes', 'nullable', 'array', 'max:' . self::MAX_MEMBERS],
            'members.*.id' => ['nullable', 'string', 'max:64'],
            'members.*.name' => ['required', 'string', 'max:120'],
            'members.*.device_id' => ['nullable', 'integer', 'distinct'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            if ($data['name'] === '') {
                throw ValidationException::withMessages(['name' => 'Geef de groep een naam.']);
            }
        }

        return $data;
    }

    private function assertUniqueName(string $name, ?int $exceptId = null): void
    {
        $clash = KakoGroup::where('user_id', auth()->id())
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
        if ($clash) {
            throw ValidationException::withMessages(['name' => 'Je hebt al een groep met deze naam.']);
        }
    }

    /**
     * Schone ledenlijst: getrimde namen, een stabiel id per lid en device_id alleen als het is gezet.
     * Een nieuw gekoppeld apparaat moet in een organisatie staan waarvan je de apparaten mag zien;
     * een eerder gekoppeld apparaat blijft staan (ook als je het recht inmiddels kwijt bent).
     */
    private function members(array $incoming, array $stored): array
    {
        $known = collect($stored)->pluck('device_id')->filter()->map(fn ($id) => (int) $id)->all();
        $wanted = collect($incoming)->pluck('device_id')->filter()->map(fn ($id) => (int) $id)->diff($known)->values()->all();
        if ($wanted) {
            $allowed = app(KakoConnectDevices::class)->visibleIds(auth()->user(), $wanted);
            if (array_diff($wanted, $allowed)) {
                throw ValidationException::withMessages(['members' => 'Je hebt geen toegang tot een van deze Connect-apparaten.']);
            }
        }

        $seen = [];

        // validated() bouwt de lijst per regel op: leden zonder id belanden daardoor achteraan. Terug in invoervolgorde.
        return collect($incoming)->sortKeys()->map(function (array $m) use (&$seen) {
            $id = trim((string) ($m['id'] ?? ''));
            while ($id === '' || isset($seen[$id])) {
                $id = 'm' . Str::lower(Str::random(10));
            }
            $seen[$id] = true;
            $member = ['id' => $id, 'name' => trim($m['name'])];
            if (! empty($m['device_id'])) {
                $member['device_id'] = (int) $m['device_id'];
            }

            return $member;
        })->filter(fn ($m) => $m['name'] !== '')->values()->all();
    }

    private function present(KakoGroup $g): array
    {
        $members = $g->members ?? [];

        return [
            'id' => $g->id,
            'name' => $g->name,
            'members' => $members,
            'member_count' => count($members),
            'updated_at' => $g->updated_at?->toIso8601String(),
        ];
    }
}
