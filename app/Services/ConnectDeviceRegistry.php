<?php
namespace App\Services;

use App\Models\{ConnectAllowedDevice, ConnectDevice, ConnectMember, ConnectOrganization, User};
use App\Support\DeviceInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConnectDeviceRegistry
{
    private function recordBound(Request $request, $token, bool $login): void
    {
        abort_unless(app(ConnectDeviceLogin::class)->validSession($token), 401);
        $binding = ConnectAllowedDevice::findOrFail($token->connect_allowed_device_id);
        $id = $request->header('X-Milmap-Installation');
        abort_unless(is_string($id) && preg_match('/^[a-f0-9-]{36}$/i', $id), 422, 'Apparaatidentificatie ontbreekt.');
        DB::transaction(function () use ($request, $token, $login, $binding, $id) {
            User::whereKey($binding->user_id)->lockForUpdate()->firstOrFail();
            $device = ConnectDevice::firstOrNew(['user_id' => $binding->user_id, 'installation_id' => strtolower($id)]);
            if (!$login) abort_unless($device->exists && !$device->revoked_at && (int) $token->connect_device_id === (int) $device->id, 401);
            if ($login) {
                // One device account has one active installation. Rebinding revokes older sessions.
                DB::table('personal_access_tokens')->where('connect_allowed_device_id', $binding->id)->where('id', '!=', $token->id)->delete();
                ConnectDevice::where('user_id', $binding->user_id)->update(['revoked_at' => now()]);
            }
            $device->fill(array_merge(DeviceInfo::hardware($request), ['name' => $binding->name,
                'organization_id' => $binding->organization_id, 'vendor_id' => $binding->udid,
                'platform' => DeviceInfo::platform($request), 'last_seen_at' => now(), 'revoked_at' => null]))->save();
            $token->forceFill(['connect_device_id' => $device->id])->save();
        });
    }

    public function record(Request $request, $token, bool $login = false): void
    {
        if (!$token || !method_exists($token, 'save') || in_array('admin', $token->abilities ?? [], true)) return;
        if ($token->connect_allowed_device_id) {
            $this->recordBound($request, $token, $login);
            return;
        }
        $id = $request->header('X-Milmap-Installation');
        if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/i', $id)) {
            // Connect's management portal itself does not consume an app-device license.
            if (!$login && ConnectMember::where('user_id', $token->tokenable_id)->where('status', 'active')->exists()) {
                abort(422, 'Apparaatidentificatie ontbreekt. Werk de MilMap-app bij en log opnieuw in.');
            }
            return;
        }
        DB::transaction(function () use ($request, $token, $login, $id) {
            $user = User::whereKey($token->tokenable_id)->lockForUpdate()->firstOrFail();
            $device = ConnectDevice::firstOrNew(['user_id' => $user->id, 'installation_id' => strtolower($id)]);
            if (!$login && $token->connect_device_id && (int) $token->connect_device_id !== (int) $device->id) abort(401, 'Deze sessie hoort bij een andere installatie. Log opnieuw in.');
            if ($device->revoked_at && !$login) abort(401, 'Deze apparaatsessie is ingetrokken. Log opnieuw in.');
            // Apparaat staat vooraf op de toegestane lijst van de organisatie (CSV): de
            // licentieplaats is dan al gereserveerd, dus geen limiet-controles.
            $vendor = DeviceInfo::hardware($request)['vendor_id'] ?? null;
            $preLicensed = $vendor && ConnectAllowedDevice::whereIn('organization_id', ConnectMember::where('user_id', $user->id)->where('status', 'active')->select('organization_id'))
                ->whereRaw('LOWER(udid) = ?', [strtolower($vendor)])->exists();
            // Organisatie met 'alleen toegestane apparaten': andere apparaten gebruiken geen plaats
            // en geen eenapparatenlimiet; het lid heeft daar enkel zijn eigen abonnement.
            $membership = $user->activeConnectMembership();
            $restricted = (bool) ConnectOrganization::whereIn('id', ConnectMember::where('user_id', $user->id)->where('status', 'active')->select('organization_id'))->where('restrict_to_allowed_devices', true)->exists();
            if ($restricted && !$preLicensed) $membership = null;
            if (!$restricted && !$preLicensed && ConnectMember::where('user_id', $user->id)->where('status', 'active')->exists()) {
                $otherDevices = ConnectDevice::where('user_id', $user->id)->connected()->when($device->exists, fn ($q) => $q->where('id', '!=', $device->id))->count();
                abort_if($otherDevices >= 1, 422, 'Dit account is al op een ander apparaat ingelogd. Log eerst uit op dat apparaat.');
            }
            if ($membership) {
                $org = ConnectOrganization::whereKey($membership->organization_id)->lockForUpdate()->firstOrFail();
                $alreadyConnected = $device->exists && $device->is_connected;

                $usesThisLicense = $alreadyConnected && (int) $device->organization_id === (int) $org->id;
                abort_if(!$preLicensed && !$usesThisLicense && $org->usedLicenses() >= $org->seat_limit, 422, 'Alle licenties zijn in gebruik (accounts en apparaten tellen elk als één). Log uit op een ander apparaat of voeg licenties toe.');
                // Once capacity drops, existing devices must also fit within the active licenses.
                $connectedIds = ConnectDevice::where('organization_id', $org->id)->connected()->whereIn('user_id', $org->members()->where('status', 'active')->select('user_id'))->orderBy('id')->limit(max(0, $org->seat_limit - $org->occupiedSeats()))->pluck('id');
                abort_if(!$preLicensed && $usesThisLicense && !$connectedIds->contains($device->id), 422, 'De apparaatlicentie is verlopen. Voeg een actieve licentie toe om dit apparaat te gebruiken.');
                $device->organization_id = $org->id;
            }
            // Connect-leden hebben maximaal één actieve app-sessie: bij een nieuwe login vervallen de oude.
            if ($login && $membership) DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->whereNotNull('connect_device_id')->where('id', '!=', $token->id)->delete();
            $device->fill(array_merge(['name' => DeviceInfo::device($request->userAgent()), 'platform' => DeviceInfo::platform($request), 'last_seen_at' => now(), 'revoked_at' => null], DeviceInfo::hardware($request)))->save();
            if ((int) $token->connect_device_id !== (int) $device->id) $token->forceFill(['connect_device_id' => $device->id])->save();
        });
    }
}
