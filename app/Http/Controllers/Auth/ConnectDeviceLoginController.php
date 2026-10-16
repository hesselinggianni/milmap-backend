<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\{ConnectAllowedDevice, User};
use App\Services\ConnectDeviceLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConnectDeviceLoginController extends Controller
{
    use Concerns\IssuesLoginSession;

    public function status(Request $r, ConnectDeviceLogin $login)
    {
        $device = $r->filled('device_id') ? $login->resolveUdid((string) $r->input('device_id')) : $login->resolve((string) $r->input('device_binding'));
        return response()->json(['managed' => true, 'organization' => ['name' => $device->organization->name]])->header('Cache-Control', 'no-store');
    }

    public function login(Request $r, ConnectDeviceLogin $login)
    {
        return DB::transaction(function () use ($r, $login) {
            $udidMode = $r->filled('device_id');
            $candidate = $udidMode ? $login->resolveUdid((string) $r->input('device_id')) : $login->resolve((string) $r->input('device_binding'));
            $device = ConnectAllowedDevice::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless($login->active($device) && ($udidMode || hash_equals((string) $device->binding_hash, hash('sha256', (string) $r->input('device_binding')))), 401);
            $r->attributes->set('connect_binding', $device);
            return $this->finalizeLogin($r, User::findOrFail($device->user_id));
        });
    }
}
