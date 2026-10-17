<?php
namespace App\Http\Controllers;
use App\Models\{ConnectMember, ConnectOrganization, ConnectPendingAddition};
use App\Services\ConnectPendingAdditions;
use Illuminate\Http\Request;
/** Gebruikers/apparaten die na het bijkopen van seats automatisch worden toegevoegd. */
class ConnectPendingAdditionController extends Controller {
    private function manager(Request $r, ConnectOrganization $org): ConnectMember {
        $member = $org->members()->where('user_id', $r->user()->id)->where('status', 'active')->whereIn('role', ['owner', 'admin'])->first();
        abort_unless($member, 403);
        return $member;
    }
    private function owned(ConnectOrganization $org, ConnectPendingAddition $pending): ConnectPendingAddition {
        abort_unless((int) $pending->organization_id === (int) $org->id, 404);
        return $pending;
    }
    public function store(Request $r, ConnectOrganization $organization, ConnectPendingAdditions $additions) {
        $manager = $this->manager($r, $organization);
        abort_unless($organization->status === 'active', 403, 'Deze organisatie is niet actief.');
        // Dezelfde regels als direct uitnodigen en apparaten toestaan.
        $data = $r->validate(['kind' => 'required|in:members,devices',
            'members' => 'required_if:kind,members|array|min:1|max:500', 'members.*.first_name' => 'required|string|max:100', 'members.*.last_name' => 'required|string|max:100',
            'members.*.email' => 'required|email|max:255', 'members.*.tags' => 'sometimes|array|max:20', 'members.*.tags.*' => 'required|string|max:80', 'members.*.role' => 'sometimes|in:member,admin',
            'devices' => 'required_if:kind,devices|array|min:1|max:500', 'devices.*.platform' => 'sometimes|in:ios,android', 'devices.*.name' => 'required|string|max:120',
            'devices.*.udid' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/']]);
        $rows = $data[$data['kind']];
        if ($data['kind'] === 'members' && $manager->role !== 'owner') foreach ($rows as $row) abort_if(($row['role'] ?? 'member') !== 'member', 403);
        $pending = $additions->create($organization, $r->user()->id, $data['kind'], $rows);
        return response()->json(['pending_addition' => $additions->present($pending)], 201);
    }
    public function show(Request $r, ConnectOrganization $organization, ConnectPendingAddition $pendingAddition, ConnectPendingAdditions $additions) {
        $this->manager($r, $organization);
        return response()->json(['pending_addition' => $additions->present($additions->reconcile($this->owned($organization, $pendingAddition)))]);
    }
    public function retry(Request $r, ConnectOrganization $organization, ConnectPendingAddition $pendingAddition, ConnectPendingAdditions $additions) {
        $this->manager($r, $organization);
        return response()->json(['pending_addition' => $additions->present($additions->retry($this->owned($organization, $pendingAddition)))]);
    }
    public function destroy(Request $r, ConnectOrganization $organization, ConnectPendingAddition $pendingAddition, ConnectPendingAdditions $additions) {
        $this->manager($r, $organization);
        return response()->json(['pending_addition' => $additions->present($additions->cancel($this->owned($organization, $pendingAddition)))]);
    }
}
