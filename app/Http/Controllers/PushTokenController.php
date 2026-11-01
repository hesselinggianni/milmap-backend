<?php

namespace App\Http\Controllers;

use App\Models\ConnectDevice;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class PushTokenController extends Controller
{
    /**
     * De app meldt het APNs-/FCM-token van deze installatie. Het token wordt aan de
     * huidige sessie gehangen (elk account), en bij een Connect-apparaat ook aan het
     * apparaat zelf voor het op afstand uitloggen.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'token'    => 'required|string|max:512',
            'platform' => 'required|in:ios,android',
        ]);

        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            // Eén toestel = één sessie: logt een ander account op dit toestel in, dan
            // verhuist het token mee en krijgt het vorige account hier geen meldingen meer.
            PersonalAccessToken::where('push_token', $data['token'])
                ->whereKeyNot($token->getKey())
                ->update(['push_token' => null, 'push_platform' => null]);

            if ($token->push_token !== $data['token'] || $token->push_platform !== $data['platform']) {
                $token->forceFill(['push_token' => $data['token'], 'push_platform' => $data['platform']])->save();
            }
        }

        $deviceId = $token->connect_device_id ?? null;
        if ($deviceId) {
            ConnectDevice::whereKey($deviceId)->update([
                'push_token'    => $data['token'],
                'push_platform' => $data['platform'],
            ]);
        }

        return response()->noContent();
    }
}
