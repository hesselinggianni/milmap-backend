<?php

namespace App\Http\Controllers;

use App\Models\ConnectDevice;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    /** De app meldt het APNs-/FCM-token van deze installatie (alleen voor Connect-apparaten). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'token'    => 'required|string|max:512',
            'platform' => 'required|in:ios,android',
        ]);

        $deviceId = $request->user()->currentAccessToken()->connect_device_id ?? null;
        if ($deviceId) {
            ConnectDevice::whereKey($deviceId)->update([
                'push_token'    => $data['token'],
                'push_platform' => $data['platform'],
            ]);
        }

        return response()->noContent();
    }
}
