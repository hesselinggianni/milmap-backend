<?php
namespace App\Services;
use App\Models\ConnectDevice;
use App\Support\DeviceInfo;
use Illuminate\Http\Request;
class ConnectDeviceRegistry {
    public function record(Request $request, $token, bool $login = false): void {
        $id = $request->header('X-Milmap-Installation');
        if (! is_string($id) || ! preg_match('/^[a-f0-9-]{36}$/i', $id) || ! $token || ! method_exists($token, 'save')) return;
        $userId = $token->tokenable_id;
        $device = ConnectDevice::firstOrCreate(['user_id' => $userId, 'installation_id' => strtolower($id)],
            ['name' => DeviceInfo::device($request->userAgent()), 'platform' => DeviceInfo::platform($request), 'last_seen_at' => now()]);
        if (! $login && $token->connect_device_id && (int) $token->connect_device_id !== (int) $device->id) abort(401, 'Deze sessie hoort bij een andere installatie. Log opnieuw in.');
        if ($device->revoked_at && ! $login) { $token->delete(); abort(401, 'Deze apparaatsessie is ingetrokken. Log opnieuw in.'); }
        if ($login || $device->last_seen_at->lt(now()->subMinute())) {
            $device->update(['last_seen_at' => now(), 'revoked_at' => null, 'name' => DeviceInfo::device($request->userAgent()), 'platform' => DeviceInfo::platform($request)]);
        }
        if ((int) $token->connect_device_id !== (int) $device->id) { $token->forceFill(['connect_device_id' => $device->id])->save(); }
    }
}
