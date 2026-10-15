<?php
namespace App\Http\Middleware;
use App\Services\ConnectDeviceRegistry;
use Closure;
use Illuminate\Http\Request;
class TrackConnectDevice {
    public function handle(Request $request, Closure $next) {
        if ($request->user()) app(ConnectDeviceRegistry::class)->record($request, $request->user()->currentAccessToken());
        $response = $next($request);
        // Op afstand gevraagde app-update (Connect-beheer): één keer doorgeven en wissen.
        $deviceId = $request->user()?->currentAccessToken()?->connect_device_id ?? null;
        if ($deviceId && \App\Models\ConnectDevice::whereKey($deviceId)->whereNotNull('update_requested_at')->update(['update_requested_at' => null])) {
            $response->headers->set('X-Milmap-Update-Requested', '1');
        }
        return $response;
    }
}
