<?php
namespace App\Services;
use App\Models\{User, ConnectMember, ConnectAllowedDevice, ConnectOrganization, ConnectAuditEvent};
use Illuminate\Http\Request;

class ConnectManagement {
    // reset_account: gebruiker mag zelf "Account resetten" (alle eigen data wissen). Standaard aan;
    // beheerders kunnen altijd resetten vanuit Connect.
    // share_location: live locatie delen op kaarten en in missies. Standaard aan; stoppen met delen
    // vereist nooit een recht (zie EnforceConnectManagement).
    public const PERMISSIONS = ['create', 'edit', 'delete', 'share_internal', 'share_external', 'public_links', 'export', 'integrations', 'reset_account', 'share_location'];
    // Rechten die nieuwer zijn dan oudere portaalbuilds; die mogen ontbreken in een policy-update.
    public const OPTIONAL_PERMISSIONS = ['reset_account', 'share_location'];
    // Rechten die bepalen wat buitenstaanders met de gegevens van de organisatie kunnen: uitnodigingen en openbare links worden
    // bij de ontvanger aan het beleid van de organisatie getoetst. Ook voor eigenaars en beheerders volgen deze het beleid;
    // een uitzondering voor hen zou uitnodigingen en links opleveren die achteraf toch geweigerd worden. Aanzetten kan in het portaal.
    public const ORG_WIDE_PERMISSIONS = ['share_external', 'public_links'];
    public const DEFAULTS = [
        'create' => true, 'edit' => true, 'delete' => false, 'share_internal' => true,
        'share_external' => false, 'public_links' => false, 'export' => false, 'integrations' => false, 'reset_account' => true,
        'share_location' => true,
        'offline_hours' => 24, 'support_name' => '', 'support_email' => '',
    ];
    public function membership(User $user): ?ConnectMember {
        if (!\Illuminate\Support\Facades\Schema::hasTable('connect_members')) return null;
        if ($user->connect_device_account) {
            $device = ConnectAllowedDevice::where('user_id', $user->id)->first();
            return ConnectMember::with('organization')->where('user_id', $user->id)->when($device, fn ($q) => $q->where('organization_id', $device->organization_id))->orderBy('id')->first();
        }
        return ConnectMember::with('organization')->where('user_id', $user->id)->where('status', 'active')->orderBy('id')->first();
    }
    public function visibleOrganizationIds(User $user): array {
        $request = request(); $key = 'connect_visible_orgs_'.$user->id;
        if ($request->attributes->has($key)) return $request->attributes->get($key);
        $internal = ConnectMember::where('user_id', $user->id)->where('status', 'active')->pluck('organization_id')->all();
        $external = ConnectOrganization::withActiveLicense()->where('management_policy->share_external', true)->pluck('id')->all();
        $result = [$internal, $external]; $request->attributes->set($key, $result); return $result;
    }
    /** Eigenaar of beheerder van de organisatie (dezelfde rollen als het Connect-portaal gebruikt); een apparaataccount nooit. */
    private function isManager(?ConnectMember $member, User $user): bool {
        return $member && !$user->connect_device_account && $member->status === 'active' && in_array($member->role, ['owner', 'admin'], true);
    }
    public function policy(ConnectOrganization $org): array { return array_replace(self::DEFAULTS, array_intersect_key($org->management_policy ?? [], self::DEFAULTS)); }
    public function context(User $user): array {
        $key = 'connect_context_'.$user->id;
        if (request()->attributes->has($key)) return request()->attributes->get($key);
        $context = $this->buildContext($user);
        request()->attributes->set($key, $context);
        return $context;
    }
    public function forgetContext(User $user): void { request()->attributes->remove('connect_context_'.$user->id); }
    private function buildContext(User $user): array {
        $member = $this->membership($user);
        $managed = (bool) $user->connect_device_account || (bool) $member;
        if (!$managed) return ['managed' => false];
        $org = $member?->organization;
        $device = $user->connect_device_account ? ConnectAllowedDevice::where('user_id', $user->id)->first() : null;
        $active = $member?->status === 'active' && $org?->licensed() && (!$user->connect_device_account || (bool) $device?->binding_hash);
        $policy = $org ? $this->policy($org) : self::DEFAULTS;
        $permissions = array_intersect_key($policy, array_flip(self::PERMISSIONS));
        // Member settings may narrow the organisation policy, never bypass it.
        foreach (array_intersect_key($member?->permissions ?? [], $permissions) as $key => $value) $permissions[$key] = $permissions[$key] && (bool) $value;
        // Eigenaars en beheerders (de leden die het portaal beheren) zijn nooit beperkt door het beleid van hun eigen
        // organisatie voor hun eigen werk: zij kunnen het beleid toch al wijzigen, dus een recht dat uit staat mag hen niet
        // in de weg zitten. Ook hun persoonlijke instellingen als lid (hierboven) vernauwen dat niet.
        // Alleen een actieve licentie telt nog: zonder blijft alles dicht (zie hieronder).
        if ($this->isManager($member, $user)) foreach (self::PERMISSIONS as $permission) {
            if (!in_array($permission, self::ORG_WIDE_PERMISSIONS, true)) $permissions[$permission] = true;
        }
        if (!$active) $permissions = array_fill_keys(self::PERMISSIONS, false);
        $until = $active ? now()->addHours((int) $policy['offline_hours']) : now();
        if ($org?->ends_at && $until->gt($org->ends_at)) $until = $org->ends_at;
        $token = $user->currentAccessToken();
        if ($token?->expires_at && $until->gt($token->expires_at)) $until = $token->expires_at;
        return [
            'managed' => true, 'active' => (bool) $active, 'device_account' => (bool) $user->connect_device_account,
            // A non-secret fingerprint lets the native app verify its current MDM
            // configuration offline without persisting the binding credential.
            'device_binding_hash' => $user->connect_device_account && ($device?->platform === 'android' || $org?->device_login_mode !== 'udid') ? $token?->connect_binding_hash : null,
            'device_login_mode' => $user->connect_device_account ? ($device?->platform === 'android' ? 'binding' : $org?->device_login_mode) : null,
            'organization' => $org ? ['id' => $org->id, 'name' => $org->name, 'logo_url' => $org->logo_url] : null,
            'role' => $member?->role, 'device_name' => $device?->name, 'assigned_employee' => $device?->assigned_employee,
            'permissions' => $permissions, 'offline_hours' => (int) $policy['offline_hours'],
            'verified_at' => now()->toIso8601String(), 'offline_until' => $until->toIso8601String(),
            'support' => ['name' => $policy['support_name'], 'email' => $policy['support_email']],
            'capabilities' => ['profile_identity' => false, 'delete_account' => false, 'personal_billing' => false, 'personal_teams' => false, 'password' => !$user->connect_device_account],
        ];
    }
    public function allows(User $user, string $permission): bool {
        $context = $this->context($user);
        return !$context['managed'] || ($context['active'] && ($context['permissions'][$permission] ?? false));
    }
    public function audit(int $organizationId, string $action, ?string $type = null, ?string $id = null, array $details = []): void {
        $user = auth('sanctum')->user();
        $device = $user?->currentAccessToken()?->connect_allowed_device_id ? ConnectAllowedDevice::find($user->currentAccessToken()->connect_allowed_device_id) : null;
        if ($device) $details = array_merge($details, ['device_name' => $device->name, 'assigned_employee' => $device->assigned_employee]);
        ConnectAuditEvent::create(['organization_id' => $organizationId, 'actor_user_id' => $user?->id,
            'device_id' => $user?->currentAccessToken()?->connect_allowed_device_id,
            'action' => $action, 'resource_type' => $type, 'resource_id' => $id, 'details' => $details]);
    }
    public function assertInvitation($resource, User $actor, string $email): void {
        $org = $resource->connectOrganization;
        if (!$org) return;
        $internal = ConnectMember::where('organization_id', $org->id)->where('status', 'active')->whereRaw('LOWER(email) = ?', [strtolower($email)])->exists();
        $permission = $internal ? 'share_internal' : 'share_external';
        $context = $this->context($actor);
        $allowed = (int) ($context['organization']['id'] ?? 0) === (int) $org->id ? $this->allows($actor, $permission) : $this->policy($org)[$permission];
        abort_unless($org->licensed() && $allowed, 403, 'Delen is door de organisatie uitgeschakeld.');
    }
    public function assertPublicResource($resource): void {
        if (!$resource?->connect_organization_id) return;
        $org = ConnectOrganization::find($resource->connect_organization_id);
        abort_unless($org && $org->licensed() && $this->policy($org)['public_links'], 403, 'Openbaar delen is door de organisatie uitgeschakeld.');
    }
}
