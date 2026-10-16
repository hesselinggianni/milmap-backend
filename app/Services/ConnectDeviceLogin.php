<?php
namespace App\Services;

use App\Models\{ConnectAllowedDevice, ConnectMember, ConnectDevice, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConnectDeviceLogin
{
    public function provision(ConnectAllowedDevice $device): string
    {
        return DB::transaction(function () use ($device) {
            $device = ConnectAllowedDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            abort_unless($device->organization->licensed(), 403);
            if (!$device->user_id) {
                $prefix = $device->platform === 'android'
                    ? 'A'.strtoupper(substr(hash('sha256', $device->organization_id.':'.$device->udid),0,48))
                    : strtoupper($device->udid);
                abort_unless(preg_match('/^[A-Z0-9-]{1,64}$/', $prefix), 422);
                $email = strtolower($prefix).'@connect.milmap.nl';
                // Never take over an existing account or merge two UDIDs sharing a prefix.
                abort_if(User::whereRaw('LOWER(email) = ?', [strtolower($email)])->exists(), 409, 'Dit UDID-prefix heeft al een account. Handmatige controle vereist.');
                $user = User::create(['first_name' => $device->name, 'last_name' => 'Connect',
                    'email' => $email, 'password' => bcrypt(Str::random(64)), 'email_verified_at' => now()]);
                $user->forceFill(['connect_device_account' => true])->save();
                $device->user_id = $user->id;
                ConnectMember::create(['organization_id' => $device->organization_id, 'user_id' => $user->id,
                    'email' => $email, 'first_name' => $device->name, 'last_name' => 'Connect',
                    'role' => 'member', 'status' => 'active', 'accepted_at' => now()]);
            }
            $secret = Str::random(64);
            $this->revoke($device);
            $device->forceFill(['binding_hash' => hash('sha256', $secret)])->save();
            app(ConnectManagement::class)->audit($device->organization_id, 'device.provisioned', 'ConnectAllowedDevice', (string) $device->id);
            return $secret;
        });
    }

    public function resolve(string $secret): ConnectAllowedDevice
    {
        abort_unless(strlen($secret) === 64, 401, 'Ongeldige apparaatbinding.');
        $device = ConnectAllowedDevice::where('binding_hash', hash('sha256', $secret))->first();
        abort_unless($device && $this->active($device), 401, 'Ongeldige apparaatbinding.');
        return $device;
    }

    /** Optionele modus: iOS-apparaat logt in met alleen zijn UDID (de organisatie koos 'udid' i.p.v. een geheime binding). */
    public function resolveUdid(string $udid): ConnectAllowedDevice
    {
        abort_unless(preg_match('/^[A-Za-z0-9-]{8,64}$/', $udid), 401, 'Ongeldig apparaat.');
        $device = ConnectAllowedDevice::whereRaw('LOWER(udid) = ?', [strtolower($udid)])->where('platform', 'ios')->first();
        abort_unless($device && $device->organization?->licensed() && $device->organization->device_login_mode === 'udid', 401, 'Dit apparaat logt in met een apparaatbinding.');
        if (!$device->user_id || !$device->binding_hash) { $this->provision($device); $device->refresh(); }
        abort_unless($this->active($device), 401, 'Apparaat niet actief.');
        return $device;
    }

    public function active(ConnectAllowedDevice $device): bool
    {
        return $device->binding_hash && $device->user_id && $device->organization?->licensed()
            && ConnectMember::where('organization_id', $device->organization_id)->where('user_id', $device->user_id)->where('status', 'active')->exists();
    }

    public function validSession($token): bool
    {
        if (!$token->connect_allowed_device_id) return !$token->tokenable?->connect_device_account;
        $device = ConnectAllowedDevice::find($token->connect_allowed_device_id);
        return $device && $this->active($device) && (int) $device->user_id === (int) $token->tokenable_id
            && hash_equals((string) $device->binding_hash, (string) $token->connect_binding_hash);
    }

    public function revoke(ConnectAllowedDevice $device): void
    {
        $device->forceFill(['binding_hash' => null])->save();
        app(ConnectManagement::class)->audit($device->organization_id, 'device.revoked', 'ConnectAllowedDevice', (string) $device->id);
        DB::table('personal_access_tokens')->where('connect_allowed_device_id', $device->id)->delete();
        if ($device->user_id) DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $device->user_id)->delete();
        if ($device->user_id) ConnectDevice::where('user_id', $device->user_id)->update(['revoked_at' => now()]);
    }
}
