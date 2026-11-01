<?php

namespace App\Services;

use App\Models\ConnectAllowedDevice;
use App\Models\ConnectMember;
use App\Models\ConnectOrganization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Connect-apparaten als KAKO-deelnemer: wie ze mag zien en hoe ze heten.
 *
 * Rechten: dezelfde als het Connect-portaal (ConnectController::manager) — alleen een
 * actieve eigenaar of beheerder van de organisatie ziet de apparaten daarvan. Gewone
 * leden en apparaataccounts zien niets; er komt dan een lege lijst terug, zodat de app
 * de keuze simpelweg niet aanbiedt. Het UDID gaat nooit mee: de KAKO-module heeft aan
 * naam en toegewezen persoon genoeg.
 */
class KakoConnectDevices
{
    public const LIMIT = 300;

    /** @return Collection<int, ConnectOrganization> */
    public function organizations(User $user): Collection
    {
        if ($user->connect_device_account || ! Schema::hasTable('connect_members')) {
            return collect();
        }

        return ConnectOrganization::whereHas('members', fn ($q) => $q
            ->where('user_id', $user->id)->where('status', 'active')->whereIn('role', ['owner', 'admin']))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return array{organizations: array, devices: array, total: int, truncated: bool}
     */
    public function search(User $user, ?string $q = null, ?int $organizationId = null): array
    {
        $organizations = $this->organizations($user);
        $ids = $organizations->pluck('id');
        if ($organizationId) {
            $ids = $ids->intersect([$organizationId])->values();
        }

        $query = ConnectAllowedDevice::query()->whereIn('organization_id', $ids);
        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('assigned_employee', 'like', $like));
        }

        $total = (clone $query)->count();
        $devices = $query->orderBy('name')->limit(self::LIMIT)->get(['id', 'organization_id', 'name', 'assigned_employee', 'platform'])
            ->map(fn ($d) => $this->present($d))->values()->all();

        return [
            'organizations' => $organizations->map(fn ($o) => ['id' => $o->id, 'name' => $o->name])->values()->all(),
            'devices' => $devices,
            'total' => $total,
            'truncated' => $total > self::LIMIT,
        ];
    }

    /** Welke van deze apparaat-id's mag deze gebruiker zien? */
    public function visibleIds(User $user, array $deviceIds): array
    {
        $deviceIds = array_values(array_unique(array_map('intval', $deviceIds)));
        if (! $deviceIds) {
            return [];
        }
        $organizationIds = $this->organizations($user)->pluck('id');
        if ($organizationIds->isEmpty()) {
            return [];
        }

        return ConnectAllowedDevice::whereIn('id', $deviceIds)->whereIn('organization_id', $organizationIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function present(ConnectAllowedDevice $d): array
    {
        $employee = trim((string) $d->assigned_employee);

        return [
            'id' => (int) $d->id,
            'organization_id' => (int) $d->organization_id,
            // Naam van de deelnemer: de toegewezen medewerker/leerling, anders de apparaatnaam.
            'label' => $employee !== '' ? $employee : (string) $d->name,
            'name' => (string) $d->name,
            'assigned_employee' => $employee !== '' ? $employee : null,
            'platform' => $d->platform,
        ];
    }
}
