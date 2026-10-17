<?php
namespace App\Http\Controllers;
use App\Models\{ConnectOrganization, ConnectMember, ConnectAllowedDevice, ConnectAuditEvent, User, Map, RouteMap, Mission};
use App\Services\ConnectManagement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ConnectManagementController extends Controller {
    private function manager(Request $r, ConnectOrganization $org): ConnectMember {
        return $org->members()->where('user_id', $r->user()->id)->where('status', 'active')->whereIn('role', ['owner', 'admin'])->firstOrFail();
    }
    public function policy(Request $r, ConnectOrganization $organization, ConnectManagement $service) {
        $this->manager($r, $organization);
        if ($r->isMethod('PUT')) {
            $rules = ['offline_hours' => 'required|integer|min:0|max:168', 'support_name' => 'nullable|string|max:190', 'support_email' => 'nullable|email|max:190'];
            // reset_account/share_location zijn nieuwer dan oudere portaalbuilds: ontbreken ze, dan blijft
            // de opgeslagen waarde staan (anders de standaard) — een oude build zet een uitgezet recht nooit stil weer aan.
            foreach ($service::PERMISSIONS as $key) $rules[$key] = in_array($key, $service::OPTIONAL_PERMISSIONS, true) ? 'sometimes|boolean' : 'required|boolean';
            $data = $r->validate($rules);
            $data += array_intersect_key($organization->management_policy ?? [], array_flip($service::OPTIONAL_PERMISSIONS));
            DB::transaction(function () use ($organization, $data, $service) {
                $organization->update(['management_policy' => $data]);
                $service->audit($organization->id, 'policy.updated', 'ConnectOrganization', (string) $organization->id, ['policy' => $data]);
            });
        }
        $service->forgetContext($r->user());
        return response()->json(['policy' => $service->policy($organization)]);
    }
    public function permissions(Request $r, ConnectOrganization $organization, ConnectMember $member, ConnectManagement $service) {
        $manager = $this->manager($r, $organization);
        abort_unless($member->organization_id === $organization->id && $member->role !== 'owner' && ($manager->role === 'owner' || $member->role === 'member'), 403);
        // 'present' i.p.v. 'required': alles terug op "Volgt beleid" stuurt een leeg object. Met 'required'
        // gaf dat 422 en bleef een eerder geblokkeerd recht (bv. share_location) stil hangen.
        $rules = ['permissions' => 'present|array:'.implode(',', $service::PERMISSIONS)];
        foreach ($service::PERMISSIONS as $key) $rules['permissions.'.$key] = 'sometimes|boolean';
        $data = $r->validate($rules);
        DB::transaction(function () use ($member, $data, $organization, $service) {
            $member->update($data);
            $service->audit($organization->id, 'member.permissions.updated', 'ConnectMember', (string) $member->id, $data);
        });
        return response()->json(['member' => $member]);
    }
    public function assignment(Request $r, ConnectOrganization $organization, ConnectAllowedDevice $device, ConnectManagement $service) {
        $this->manager($r, $organization); abort_unless($device->organization_id === $organization->id, 404);
        $data = $r->validate(['assigned_employee' => 'nullable|string|max:190', 'name' => 'required|string|max:190']);
        DB::transaction(function () use ($device, $data, $organization, $service) {
            $device->forceFill($data)->save();
            if ($device->user_id) User::whereKey($device->user_id)->update(['first_name' => $data['name']]);
            $service->audit($organization->id, 'device.assignment.updated', 'ConnectAllowedDevice', (string) $device->id);
        });
        return response()->json(['device' => $device]);
    }
    public function audit(Request $r, ConnectOrganization $organization) {
        $this->manager($r, $organization);
        $page = ConnectAuditEvent::where('organization_id', $organization->id)->orderByDesc('id')->paginate(50);
        // Het portaal toont namen, geen kale account-/apparaatnummers.
        $events = collect($page->items());
        $userIds = $events->pluck('actor_user_id')->merge($events->where('resource_type', 'User')->pluck('resource_id'))->filter()->unique()->values();
        $members = ConnectMember::where('organization_id', $organization->id)->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $users = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $personName = function ($id) use ($members, $users) {
            if (!$id) return null;
            $member = $members->get($id);
            $name = $member ? trim($member->first_name.' '.$member->last_name) : trim(($users->get($id)?->first_name ?? '').' '.($users->get($id)?->last_name ?? ''));
            return $name !== '' ? $name : 'Onbekend account';
        };
        $deviceIds = $events->pluck('device_id')->merge($events->where('resource_type', 'ConnectAllowedDevice')->pluck('resource_id'))->filter()->unique()->values();
        $devices = ConnectAllowedDevice::where('organization_id', $organization->id)->whereIn('id', $deviceIds)->pluck('name', 'id');
        $titles = [];
        foreach (['Map' => [Map::class, 'title'], 'RouteMap' => [RouteMap::class, 'title'], 'Mission' => [Mission::class, 'name']] as $type => [$model, $column]) {
            $ids = $events->where('resource_type', $type)->pluck('resource_id')->filter()->unique()->values();
            $titles[$type] = $ids->isEmpty() ? collect() : $model::withTrashed()->where('connect_organization_id', $organization->id)->whereIn('id', $ids)->pluck($column, 'id');
        }
        $memberNames = ConnectMember::where('organization_id', $organization->id)
            ->whereIn('id', $events->where('resource_type', 'ConnectMember')->pluck('resource_id')->filter()->unique()->values())
            ->get()->mapWithKeys(fn ($m) => [$m->id => trim($m->first_name.' '.$m->last_name)]);
        $page->getCollection()->transform(function ($event) use ($personName, $devices, $titles, $memberNames, $organization) {
            $id = $event->resource_id;
            $event->setAttribute('actor_name', $personName($event->actor_user_id));
            $event->setAttribute('device_name', $event->device_id ? ($devices->get($event->device_id) ?? ($event->details['device_name'] ?? 'Verwijderd apparaat')) : null);
            $event->setAttribute('resource_label', match ($event->resource_type) {
                'User' => $personName($id),
                'ConnectMember' => $memberNames->get($id) ?: null,
                'ConnectAllowedDevice' => $devices->get($id),
                'ConnectOrganization' => $organization->name,
                'Map', 'RouteMap', 'Mission' => $titles[$event->resource_type]->get($id),
                default => null,
            });
            return $event;
        });
        return response()->json($page);
    }
    public function resources(Request $r, ConnectOrganization $organization) {
        $this->manager($r, $organization);
        $result = [];
        foreach (['maps' => Map::class, 'routes' => RouteMap::class, 'missions' => Mission::class] as $type => $model) {
            $title = $type === 'missions' ? 'name' : 'title';
            $result[$type] = $model::withTrashed()->where('connect_organization_id', $organization->id)->select(['id', $title, 'owner_id', 'deleted_at', 'updated_at'])->orderByDesc('updated_at')->paginate(50, ['*'], $type.'_page');
        }
        return response()->json($result);
    }
    public function transfer(Request $r, ConnectOrganization $organization, ConnectManagement $service) {
        $this->manager($r, $organization);
        $data = $r->validate(['from_user_id' => 'required|integer|different:to_user_id', 'to_user_id' => 'required|integer']);
        // Former members are valid sources. Targets must be active, distinct
        // members of this same tenant. Never move personal or foreign data.
        abort_unless($organization->members()->where('user_id', $data['from_user_id'])->exists(), 422);
        abort_unless($organization->members()->where('user_id', $data['to_user_id'])->where('status', 'active')->exists(), 422);
        $counts = DB::transaction(function () use ($organization, $data, $service) {
            $counts = [];
            foreach (['maps' => Map::class, 'routes' => RouteMap::class, 'missions' => Mission::class] as $type => $model) {
                $counts[$type] = $model::withTrashed()->where('connect_organization_id', $organization->id)->where('owner_id', $data['from_user_id'])->update(['owner_id' => $data['to_user_id']]);
            }
            $service->audit($organization->id, 'resources.transferred', null, null, $data + ['counts' => $counts]);
            return $counts;
        });
        return response()->json(['transferred' => $counts]);
    }
    public function authorizeAction(Request $r, ConnectManagement $service) {
        $data = $r->validate(['permission' => 'required|in:'.implode(',', $service::PERMISSIONS)]);
        abort_unless($service->allows($r->user(), $data['permission']), 403, 'Deze actie is door je organisatie uitgeschakeld.');
        $context = $service->context($r->user());
        if ($context['managed']) $service->audit($context['organization']['id'], 'action.authorized', null, null, $data);
        return response()->json(['allowed' => true, 'connect_management' => $context]);
    }
}
