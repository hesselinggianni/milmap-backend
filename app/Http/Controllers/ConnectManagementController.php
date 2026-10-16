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
            foreach ($service::PERMISSIONS as $key) $rules[$key] = 'required|boolean';
            $data = $r->validate($rules);
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
        $rules = ['permissions' => 'required|array:'.implode(',', $service::PERMISSIONS)];
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
        return response()->json(ConnectAuditEvent::where('organization_id', $organization->id)->orderByDesc('id')->paginate(50));
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
