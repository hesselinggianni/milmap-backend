<?php
namespace App\Http\Controllers;
use App\Models\ConnectOrganization;
use App\Models\ConnectMember;
use App\Models\ConnectDevice;
use App\Models\ConnectAllowedDevice;
use App\Services\ConnectRoster;
use App\Support\DeviceInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Stripe\StripeClient;
use App\Models\ConnectQuoteRequest;
use App\Models\Map;
use App\Models\RouteMap;
use App\Models\Mission;
class ConnectController extends Controller {
    private function manager(Request $r, ConnectOrganization $organization): ConnectMember {
        $member = $organization->members()->where('user_id', $r->user()->id)->where('status', 'active')->whereIn('role', ['owner', 'admin'])->first();
        abort_unless($member, 403, 'Je hebt geen beheerrechten voor deze organisatie.'); return $member;
    }
    public function index(Request $r) {
        return response()->json(['organizations' => ConnectOrganization::whereHas('members', fn ($q) => $q->where('user_id', $r->user()->id)->where('status', 'active')->whereIn('role', ['owner', 'admin']))->get()]);
    }
    public function access(Request $r) {
        $memberships = ConnectMember::with('organization')->where('user_id', $r->user()->id)->where('status', 'active')->get();
        return response()->json(['management' => app(\App\Services\ConnectManagement::class)->context($r->user()), 'enterprise' => $memberships->contains(fn ($m) => $m->organization?->licensed()), 'memberships' => $memberships->map(fn ($m) => ['organization' => $m->organization, 'role' => $m->role, 'licensed' => (bool) $m->organization?->licensed()])->values()]);
    }
    public function startTrial(Request $r) {
        $data = $r->validate(['name' => 'required|string|max:160']);
        abort_if($r->user()->isEmailVerificationWalled(), 403, 'Bevestig eerst je e-mailadres om de proefperiode te starten.');
        $organization = app(\App\Services\ConnectTrial::class)->start($r->user(), trim($data['name']));
        return response()->json(['message' => 'Je proefperiode van '.\App\Services\ConnectTrial::DAYS.' dagen is gestart.', 'organization' => $organization], 201);
    }
    public function requestQuote(Request $r) {
        $data = $r->validate(['company_name'=>'required|string|max:190','contact_name'=>'required|string|max:190','email'=>'required|email|max:190','billing_email'=>'nullable|email|max:190','phone'=>'nullable|string|max:50','seat_count'=>'required|integer|min:1|max:100000','purpose'=>'nullable|string|max:190','requirements'=>'nullable|string|max:5000']);
        $data['user_id'] = $r->user('sanctum')?->id; $quote = app(\App\Services\ConnectApplication::class)->create($data);
        try { Mail::to('hello@milmap.nl')->send(new \App\Mail\ConnectQuoteRequestMail($quote->toArray())); } catch (\Throwable $e) { report($e); }
        return response()->json(['message'=>'Je offerteaanvraag is verstuurd. We nemen snel contact met je op.','id'=>$quote->id], 201);
    }
    public function show(Request $r, ConnectOrganization $organization) {
        $manager = $this->manager($r, $organization);
        $organization->load('licenses');
        $members = $organization->members()->orderBy('first_name')->get();
        $devices = ConnectDevice::whereIn('user_id', $members->where('status', 'active')->pluck('user_id')->filter())->with('user:id,first_name,last_name,email')->orderByDesc('last_seen_at')->get();
        $license = $organization->deviceLicenseStatus();
        $devices->each(fn ($d) => $d->setAttribute('licensed', $license['devices'][$d->id] ?? null));
        $userIds = $members->pluck('user_id')->filter()->values();
        $mapCounts = Map::selectRaw('owner_id, COUNT(*) as c')->whereIn('owner_id', $userIds)->groupBy('owner_id')->pluck('c','owner_id');
        $routeCounts = RouteMap::selectRaw('owner_id, COUNT(*) as c')->whereIn('owner_id', $userIds)->groupBy('owner_id')->pluck('c','owner_id');
        $missionCounts = Mission::selectRaw('owner_id, COUNT(*) as c')->whereIn('owner_id', $userIds)->groupBy('owner_id')->pluck('c','owner_id');
        $lastSeen = ConnectDevice::selectRaw('user_id, MAX(last_seen_at) as seen')->whereIn('user_id', $userIds)->groupBy('user_id')->pluck('seen', 'user_id');
        // Browsergebruik staat niet als apparaat geregistreerd; de gebruiker zelf houdt wel last_seen_at bij.
        $userSeen = \App\Models\User::whereIn('id', $userIds)->pluck('last_seen_at', 'id');
        $members->each(function ($member) use ($mapCounts, $routeCounts, $missionCounts, $lastSeen, $userSeen) { $seen = array_filter([$lastSeen[$member->user_id] ?? null, $userSeen[$member->user_id] ?? null]); $member->setAttribute('last_seen_at', $seen ? max(array_map(fn ($s) => \Illuminate\Support\Carbon::parse($s), $seen)) : null); $member->setAttribute('stats', ['maps' => (int) ($mapCounts[$member->user_id] ?? 0), 'route_maps' => (int) ($routeCounts[$member->user_id] ?? 0), 'missions' => (int) ($missionCounts[$member->user_id] ?? 0)]); });
        return response()->json(['organization' => $organization, 'user' => ['name' => trim($r->user()->first_name.' '.$r->user()->last_name), 'email' => $r->user()->email], 'role' => $manager->role, 'licensed' => $organization->licensed(),
            'occupied_seats' => $organization->occupiedSeats(), 'used_licenses' => $organization->usedLicenses(), 'occupied_devices' => $organization->occupiedDevices(), 'members' => $members, 'devices' => $devices, 'renewal' => $organization->renewalInfo(), 'trial' => $organization->trialInfo(), 'grace' => $organization->graceInfo(), 'unlicensed_devices' => collect($license['allowed'])->merge($license['devices'])->filter(fn ($v) => $v === false)->count(), 'allowed_devices' => $this->allowedDevices($organization, $license['allowed']), 'pending_additions' => app(\App\Services\ConnectPendingAdditions::class)->recent($organization),
            'management_policy' => app(\App\Services\ConnectManagement::class)->policy($organization), 'billing_details' => $organization->billingProfile($r->user()->email),
            'analytics' => ['maps' => $mapCounts->sum(), 'route_maps' => $routeCounts->sum(), 'missions' => $missionCounts->sum()]]);
    }
    public function upgradeLicense(Request $r, ConnectOrganization $organization) {
        $this->manager($r, $organization);
        abort(403, 'Extra licenties moeten eerst worden aangeschaft. Neem contact op met MilMap of gebruik de betaalpagina.');
    }
    public function checkoutSeats(Request $r, ConnectOrganization $organization) {
        $this->manager($r, $organization);
        $data = $r->validate(['additional_seats' => 'required|integer|min:1|max:100000']);
        return response()->json(['checkout_url' => rtrim(config('connect.url'), '/').'/licenties/bestellen?seats='.$data['additional_seats']]);
    }
    public function invoiceRequest(Request $r, ConnectOrganization $organization) {
        return $this->checkoutSeats($r, $organization);
    }
    public function invite(Request $r, ConnectOrganization $organization, ConnectRoster $roster) {
        $manager = $this->manager($r, $organization);
        $data = $r->validate(['members' => 'required|array|min:1|max:500', 'members.*.first_name' => 'required|string|max:100',
            'members.*.last_name' => 'required|string|max:100', 'members.*.email' => 'required|email|max:255',
            'members.*.tags' => 'sometimes|array|max:20', 'members.*.tags.*' => 'required|string|max:80', 'members.*.role' => 'sometimes|in:member,admin']);
        if ($manager->role !== 'owner') foreach ($data['members'] as $row) abort_if(($row['role'] ?? 'member') !== 'member', 403);
        return response()->json($roster->invite($organization, $data['members']), 201);
    }
    public function update(Request $r, ConnectOrganization $organization, ConnectMember $member) {
        $manager = $this->manager($r, $organization); abort_unless($member->organization_id === $organization->id, 404);
        abort_if($member->role === 'owner' || ($member->role === 'admin' && $manager->role !== 'owner'), 403);
        $data = $r->validate(['first_name' => 'sometimes|string|max:100', 'last_name' => 'sometimes|string|max:100', 'email' => 'sometimes|email|max:255', 'tags' => 'required|array|max:20', 'tags.*' => 'required|string|max:80', 'role' => 'required|in:member,admin']);
        if (array_key_exists('email', $data) && strtolower($data['email']) !== strtolower($member->email)) {
            abort_if($member->status !== 'pending', 422, 'Het e-mailadres van een actief account kan niet vanuit Connect worden aangepast.');
            $data['email'] = strtolower(trim($data['email']));
            abort_if(ConnectMember::where('organization_id', $organization->id)->where('email', $data['email'])->where('id', '<>', $member->id)->whereIn('status', ['active', 'pending'])->exists(), 422, 'Dit e-mailadres staat al in de organisatie.');
            $data['token_hash'] = null;
            $data['expires_at'] = null;
            $data['mail_sent_at'] = null;
        }
        abort_if($data['role'] === 'admin' && $manager->role !== 'owner', 403); $member->update($data); return response()->json($member);
    }
    public function welcome(Request $r, ConnectOrganization $organization, ConnectMember $member) {
        $manager = $this->manager($r, $organization);
        abort_unless($member->organization_id === $organization->id && $member->user_id, 404);
        $password = bin2hex(random_bytes(6));
        $user = $member->user;
        $user->forceFill(['password' => Hash::make($password)])->save();
        Mail::to($user->email)->send(new \App\Mail\ConnectWelcome($organization->name, $member->first_name, $user->email, $password));
        return response()->json(['message' => 'Nieuw wachtwoord en welkomstmail verstuurd.']);
    }
    public function remove(Request $r, ConnectOrganization $organization, ConnectMember $member) {
        $manager = $this->manager($r, $organization); abort_unless($member->organization_id === $organization->id, 404);
        abort_if($member->role === 'owner' || ($member->role === 'admin' && $manager->role !== 'owner'), 403);
        DB::transaction(function () use ($organization, $member) {
            ConnectOrganization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $member->update(['status' => 'removed', 'token_hash' => null]);
        }); return response()->noContent();
    }
    /**
     * Account resetten door een beheerder. Apparaataccounts zijn volledig van de organisatie:
     * alles gaat weg. Een persoonlijk account verliest alleen de werkgegevens van deze organisatie.
     */
    public function resetMember(Request $r, ConnectOrganization $organization, ConnectMember $member) {
        $manager = $this->manager($r, $organization); abort_unless($member->organization_id === $organization->id && $member->user_id, 404);
        abort_if($member->role === 'owner' || ($member->role === 'admin' && $manager->role !== 'owner'), 403);
        return $this->resetAccount($organization, $member->user, 'ConnectMember', (string) $member->id);
    }
    private function resetAccount(ConnectOrganization $organization, \App\Models\User $user, string $type, string $id) {
        $reset = app(\App\Services\AccountReset::class);
        $full = (bool) $user->connect_device_account;
        $counts = $full ? $reset->reset($user) : $reset->resetOrganizationData($user, $organization->id);
        app(\App\Services\ConnectManagement::class)->audit($organization->id, 'account.reset', $type, $id, ['counts' => $counts, 'scope' => $full ? 'account' : 'organization']);
        return response()->json(['message' => $full ? 'Account gereset: alle gegevens zijn verwijderd.' : 'Werkgegevens van de organisatie verwijderd.', 'scope' => $full ? 'account' : 'organization', 'deleted' => $counts]);
    }
    public function resend(Request $r, ConnectOrganization $organization, ConnectMember $member) {
        $manager = $this->manager($r, $organization); abort_unless($member->organization_id === $organization->id, 404);
        abort_if($member->status !== 'pending' || ($member->role === 'admin' && $manager->role !== 'owner'), 403);
        // A new token invalidates the old invitation; transaction preserves the seat reservation.
        return response()->json(app(\App\Services\ConnectResend::class)->send($organization, $member));
    }
    public function preview(Request $r) {
        $data = $r->validate(['token' => 'required|string|size:64']);
        $member = ConnectMember::where('token_hash', hash('sha256', $data['token']))->with('organization')->firstOrFail();
        abort_unless($member->status === 'pending' && $member->expires_at > now(), 410);
        return response()->json(['organization' => $member->organization->name, 'first_name' => $member->first_name, 'licensed' => $member->organization->licensed()]);
    }
    public function accept(Request $r, ConnectRoster $roster) {
        $data = $r->validate(['token' => 'required|string|size:64']);
        $member = $roster->accept($r->user(), $data['token']); return response()->json(['organization' => $member->organization->name]);
    }
    public function revokeDevice(Request $r, ConnectOrganization $organization, ConnectDevice $device) {
        $this->manager($r, $organization);
        abort_unless($organization->members()->where('user_id', $device->user_id)->where('status', 'active')->exists(), 404);
        // Silent push: de app logt direct uit i.p.v. pas bij het volgende API-verzoek.
        \App\Services\PushSender::silentToDevice($device, ['action' => 'logout']);
        DB::transaction(function () use ($device) {
            $binding = ConnectAllowedDevice::where('user_id', $device->user_id)->where('organization_id', $device->organization_id)->lockForUpdate()->first();
            if ($binding) app(\App\Services\ConnectDeviceLogin::class)->revoke($binding);
            $device->update(['revoked_at' => now()]);
            DB::table('personal_access_tokens')->where('connect_device_id', $device->id)->where('tokenable_id', $device->user_id)->where('tokenable_type', \App\Models\User::class)->delete();
        }); return response()->noContent();
    }
    /** Toegestane apparaten met status: gekoppeld = er is al een installatie met dit UDID in deze organisatie. */
    private function allowedDevices(ConnectOrganization $organization, ?array $licensed = null) {
        $licensed ??= $organization->deviceLicenseStatus()['allowed'];
        $seen = ConnectDevice::where('organization_id', $organization->id)->whereNotNull('vendor_id')->get()->keyBy(fn ($d) => strtolower($d->vendor_id));
        return ConnectAllowedDevice::where('organization_id', $organization->id)->orderBy('name')->get()->map(function ($a) use ($seen, $licensed) {
            $d = $seen->get(strtolower($a->udid));
            return ['user_id' => $a->user_id, 'assigned_employee' => $a->assigned_employee, 'platform' => $a->platform, 'id' => $a->id, 'name' => $a->name, 'udid' => $a->udid, 'licensed' => $licensed[$a->id] ?? false, 'linked' => (bool) $d, 'last_seen_at' => $d?->last_seen_at,
                'device_id' => $d?->id, 'connected' => (bool) $d?->is_connected, 'app_version' => $d?->app_version, 'model' => $d?->model, 'update_pending' => (bool) $d?->update_requested_at];
        });
    }
    public function importAllowedDevices(Request $r, ConnectOrganization $organization) {
        $this->manager($r, $organization);
        $data = $r->validate(['devices' => 'required|array|min:1|max:500', 'devices.*.platform' => 'sometimes|in:ios,android', 'devices.*.name' => 'required|string|max:120', 'devices.*.udid' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/']]);
        $rows = collect($data['devices'])->map(fn ($d) => ['name' => trim($d['name']), 'udid' => strtoupper(trim($d['udid'])), 'platform' => $d['platform'] ?? 'ios'])->unique('udid')->values();
        $new = $rows->reject(fn ($d) => ConnectAllowedDevice::where('organization_id', $organization->id)->where('udid', $d['udid'])->exists())->count();
        $free = max(0, $organization->seat_limit - $organization->usedLicenses());
        abort_if($new > $free, 422, "Er zijn nog {$free} licenties beschikbaar (accounts en apparaten tellen elk als één); deze upload voegt {$new} nieuwe apparaten toe.");
        DB::transaction(function () use ($rows, $organization) {
            foreach ($rows as $d) ConnectAllowedDevice::updateOrCreate(['organization_id' => $organization->id, 'udid' => $d['udid']], ['name' => $d['name'], 'platform' => $d['platform']]);
        });
        return response()->json(['imported' => $rows->count(), 'new' => $new, 'allowed_devices' => $this->allowedDevices($organization)]);
    }
    public function createMdmToken(Request $r, ConnectOrganization $organization) {
        $this->manager($r, $organization);
        $token = 'mmc_' . \Illuminate\Support\Str::random(40);
        $organization->forceFill(['mdm_token_hash' => hash('sha256', $token), 'mdm_token_prefix' => substr($token, 0, 8), 'mdm_token_created_at' => now(), 'mdm_token_last_used_at' => null])->save();
        return response()->json(['token' => $token, 'organization' => $organization->fresh()], 201);
    }
    public function revokeMdmToken(Request $r, ConnectOrganization $organization) {
        $this->manager($r, $organization);
        $organization->forceFill(['mdm_token_hash' => null, 'mdm_token_prefix' => null, 'mdm_token_created_at' => null, 'mdm_token_last_used_at' => null])->save();
        return response()->noContent();
    }
    private function mdmOrganization(Request $r): ConnectOrganization {
        $token = (string) $r->bearerToken();
        $org = $token !== '' ? ConnectOrganization::where('mdm_token_hash', hash('sha256', $token))->first() : null;
        abort_unless($org, 401, 'Ongeldig MDM-token.');
        $org->forceFill(['mdm_token_last_used_at' => now()])->saveQuietly();
        return $org;
    }
    public function mdmList(Request $r) {
        $org = $this->mdmOrganization($r);
        return response()->json(['devices' => ConnectAllowedDevice::where('organization_id', $org->id)->orderBy('name')->get(['name', 'udid', 'platform']), 'seat_limit' => $org->seat_limit]);
    }
    public function mdmAdd(Request $r) {
        $org = $this->mdmOrganization($r);
        abort_unless($org->licensed(), 403, 'De licentie van deze organisatie is niet actief.');
        $data = $r->validate(['udid' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'], 'name' => 'nullable|string|max:120', 'platform' => 'sometimes|in:ios,android']);
        $udid = strtoupper(trim($data['udid']));
        $existing = ConnectAllowedDevice::where('organization_id', $org->id)->where('udid', $udid)->first();
        if (! $existing) {
            abort_if($org->usedLicenses() >= $org->seat_limit, 422, "Alle {$org->seat_limit} licenties zijn in gebruik.");
        }
        $name = trim($data['name'] ?? '') ?: ($existing?->name ?? $udid);
        $device = ConnectAllowedDevice::updateOrCreate(['organization_id' => $org->id, 'udid' => $udid], ['name' => $name, 'platform' => $data['platform'] ?? $existing?->platform ?? 'ios']);
        return response()->json(['device' => ['name' => $device->name, 'udid' => $device->udid, 'platform' => $device->platform], 'created' => ! $existing], $existing ? 200 : 201);
    }
    public function mdmBinding(Request $r, string $udid) {
        $org = $this->mdmOrganization($r);
        $device = ConnectAllowedDevice::where('organization_id', $org->id)->whereRaw('LOWER(udid) = ?', [strtolower($udid)])->firstOrFail();
        $secret = app(\App\Services\ConnectDeviceLogin::class)->provision($device);
        return response()->json(['device_binding' => $secret])->header('Cache-Control', 'no-store');
    }
    public function mdmRemove(Request $r, string $udid) {
        $org = $this->mdmOrganization($r);
        $device = ConnectAllowedDevice::where('organization_id', $org->id)->whereRaw('LOWER(udid) = ?', [strtolower($udid)])->first();
        if ($device) DB::transaction(function () use ($device) { app(\App\Services\ConnectDeviceLogin::class)->revoke($device); $device->delete(); });
        return response()->noContent();
    }
    public function updateAllowedDevice(Request $r, ConnectOrganization $organization, ConnectAllowedDevice $allowedDevice) {
        $this->manager($r, $organization);
        abort_unless((int) $allowedDevice->organization_id === (int) $organization->id, 404);
        $data = $r->validate(['name' => 'required|string|max:120', 'udid' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/']]);
        $udid = strtoupper(trim($data['udid']));
        DB::transaction(function () use ($allowedDevice, $organization, $data, $udid) {
            if ($udid !== strtoupper($allowedDevice->udid)) {
                abort_if(ConnectAllowedDevice::where('organization_id', $organization->id)->where('udid', $udid)->where('id', '!=', $allowedDevice->id)->exists(), 422, 'Dit apparaat-ID staat al in de lijst.');
                // Nieuw ID = ander apparaat: de oude koppeling/sessie vervalt.
                app(\App\Services\ConnectDeviceLogin::class)->revoke($allowedDevice);
            }
            $allowedDevice->update(['name' => trim($data['name']), 'udid' => $udid]);
        });
        return response()->json(['allowed_devices' => $this->allowedDevices($organization)]);
    }
    public function deleteAllowedDevice(Request $r, ConnectOrganization $organization, ConnectAllowedDevice $allowedDevice) {
        $this->manager($r, $organization);
        abort_unless((int) $allowedDevice->organization_id === (int) $organization->id, 404);
        DB::transaction(function () use ($allowedDevice) { app(\App\Services\ConnectDeviceLogin::class)->revoke($allowedDevice); $allowedDevice->delete(); }); return response()->noContent();
    }
    /** Apparaataccount leegmaken (bijv. iPad naar de volgende gebruiker); koppeling en login blijven. */
    public function resetAllowedDevice(Request $r, ConnectOrganization $organization, ConnectAllowedDevice $allowedDevice) {
        $this->manager($r, $organization);
        abort_unless((int) $allowedDevice->organization_id === (int) $organization->id, 404);
        abort_unless($allowedDevice->user_id && ($user = \App\Models\User::find($allowedDevice->user_id)), 422, 'Dit apparaat heeft nog geen account: er is niets te resetten.');
        return $this->resetAccount($organization, $user, 'ConnectAllowedDevice', (string) $allowedDevice->id);
    }
    /**
     * Publiek: huisstijl voor het inlogscherm op een zakelijk apparaat. De app stuurt het
     * apparaat-ID (Capacitor Device.getId); alleen naam + logo + achtergrond komen terug,
     * en alleen als de organisatie een actieve licentie heeft. Onbekend ID → 404 (geen lek
     * over welke ID's bestaan, zelfde antwoord als "geen zakelijk apparaat").
     */
    public function deviceBranding(Request $r) {
        $data = $r->validate(['device_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/']]);
        $allowed = ConnectAllowedDevice::whereRaw('LOWER(udid) = ?', [strtolower($data['device_id'])])->with('organization')->get()
            ->first(fn ($a) => $a->organization?->licensed());
        abort_unless($allowed, 404);
        $o = $allowed->organization;
        return response()->json(['organization' => ['name' => $o->name, 'logo_url' => $o->logo_url, 'login_background_url' => $o->login_background_url]]);
    }
    public function updateAccount(Request $r, ConnectOrganization $organization) {
        $this->manager($r, $organization);
        $data = $r->validate(['name' => 'sometimes|required|string|max:190', 'contact_name' => 'sometimes|nullable|string|max:190',
            'contact_email' => 'sometimes|nullable|email|max:190', 'billing_email' => 'sometimes|nullable|email|max:190']);
        // Factuur-e-mail via de facturatie, zodat de klantgegevens voor facturen meegaan (oudere Connect-versies sturen dit nog hierheen).
        if (array_key_exists('billing_email', $data)) app(\App\Services\ConnectBilling::class)->updateDetails($organization, ['email' => $data['billing_email']]);
        $organization->update(\Illuminate\Support\Arr::except($data, ['billing_email']));
        app(\App\Services\ConnectManagement::class)->audit($organization->id, 'account.updated', 'ConnectOrganization', $organization->id, ['fields' => array_keys($data)]);
        return response()->json(['organization' => $organization->fresh()]);
    }
    public function updateDeviceLoginMode(Request $r, ConnectOrganization $organization) {
        $this->manager($r, $organization);
        $data = $r->validate(['device_login_mode' => 'required|in:binding,udid']);
        $organization->update($data);
        app(\App\Services\ConnectManagement::class)->audit($organization->id, 'device_login_mode.updated', 'ConnectOrganization', $organization->id, $data);
        return response()->json(['organization' => $organization->fresh()]);
    }
    public function updateDeviceRestriction(Request $r, ConnectOrganization $organization) {
        $this->manager($r, $organization);
        $data = $r->validate(['restrict_to_allowed_devices' => 'required|boolean']);
        $organization->update($data);
        return response()->json(['organization' => $organization->fresh()]);
    }
    public function updateBranding(Request $r, ConnectOrganization $organization) {
        $this->manager($r, $organization);
        $r->validate([
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,webp,svg|max:2048',
            'login_background' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:6144',
            'remove_logo' => 'nullable|boolean', 'remove_login_background' => 'nullable|boolean',
        ]);
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        foreach (['logo' => 'logo_path', 'login_background' => 'login_background_path'] as $field => $column) {
            $old = $organization->getRawOriginal($column);
            if ($r->hasFile($field)) {
                $file = $r->file($field);
                if (strtolower($file->getClientOriginalExtension()) === 'svg' || str_contains((string) $file->getMimeType(), 'svg')) {
                    // SVG kan scripts bevatten: strip actieve inhoud voor opslag.
                    $svg = (string) file_get_contents($file->getRealPath());
                    $svg = preg_replace('#<\s*(script|foreignObject|iframe|object|embed)\b.*?<\s*/\s*\1\s*>#is', '', $svg);
                    $svg = preg_replace('#<\s*(script|foreignObject|iframe|object|embed)\b[^>]*/?>#is', '', $svg);
                    $svg = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $svg);
                    $svg = preg_replace('#(href|xlink:href)\s*=\s*("|\')\s*(javascript|data):[^"\']*\2#i', '', $svg);
                    $path = "connect/{$organization->id}/" . \Illuminate\Support\Str::random(40) . '.svg';
                    $disk->put($path, $svg);
                    $organization->{$column} = $path;
                } else {
                    $organization->{$column} = $file->store("connect/{$organization->id}", 'public');
                }
            } elseif ($r->boolean("remove_{$field}")) {
                $organization->{$column} = null;
            } else continue;
            if ($old) $disk->delete($old);
        }
        $organization->save();
        return response()->json(['organization' => $organization->fresh()]);
    }
    public function requestDeviceUpdate(Request $r, ConnectOrganization $organization, ConnectDevice $device) {
        $this->manager($r, $organization);
        abort_unless($organization->members()->where('user_id', $device->user_id)->where('status', 'active')->exists(), 404);
        abort_unless($device->is_connected, 422, 'Dit apparaat is niet ingelogd; een update kan alleen worden aangevraagd voor een ingelogd apparaat.');
        $device->update(['update_requested_at' => now()]);
        return response()->json(['message' => 'Update aangevraagd. De app haalt hem op zodra ze online is en geopend wordt.']);
    }
}
