<?php
namespace App\Http\Middleware;
use App\Services\ConnectManagement;
use App\Models\{ConnectMember, Map, Mission, RouteMap};
use Closure;
use Illuminate\Http\Request;
class EnforceConnectManagement {
    public function handle(Request $r, Closure $next) {
        // Explicit Sanctum lookup also covers authenticated routes outside the
        // main app route group (account deletion, integrations, billing).
        $user = $r->user('sanctum');
        if (!$user || !$r->is('api/v1/*') || $r->is('api/v1/admin/*')) return $next($r);
        $service = app(ConnectManagement::class);
        $context = $service->context($user);
        // Gedecodeerd pad: de router matcht op rawurldecode($path), dus 'upd%61te' mag hier niet langs de regels glippen.
        $path = preg_replace('#^api/v1/#', '', $r->decodedPath());
        $write = !in_array($r->method(), ['GET', 'HEAD', 'OPTIONS']);
        $deny = fn ($permission) => response()->json(['code' => 'connect_policy_denied', 'permission' => $permission,
            'message' => 'Deze actie wordt beheerd door je organisatie. Neem contact op met je beheerder.', 'connect_management' => $context, 'user_id' => $user->id], 403);
        // Connect management endpoints have their own manager/tenant checks.
        if (str_starts_with($path, 'connect/')) {
            $response = $next($r);
            if ($write && $response->getStatusCode() < 400 && str_contains($r->route()->getActionName(), 'ConnectController@')) {
                $org = $r->route('organization');
                if ($org instanceof \App\Models\ConnectOrganization) $service->audit($org->id, $r->method().' '.$r->route()->uri());
            }
            if ($write) $service->forgetContext($user);
            $response->headers->set('X-Milmap-Management', base64_encode(json_encode(['user_id' => $user->id] + $service->context($user))));
            return $response;
        }
        if ($context['managed']) {
            if (!$context['active'] && !in_array($path, ['logout', 'logout-all', 'users/me', 'auth/handoff'])) return $deny('access');
            if (preg_match('#^(billing(?:/|$)|teams(?:/|$)|team-memberships(?:/|$)|user/account/deletion(?:/|$))#', $path)
                || ($write && preg_match('#^users(?:/|$)#', $path))) return $deny('account_management');
            if ($path === 'user/profile' && $write && array_intersect(array_keys($r->all()), ['first_name', 'last_name', 'email'])) return $deny('profile_identity');
            if ($path === 'user/avatar' && $write) return $deny('profile_identity');
            if ($path === 'user/password' && $user->connect_device_account) return $deny('password');
            if ((str_contains($path, 'garmin') || str_contains($path, 'integrations')) && !$service->allows($user, 'integrations')) return $deny('integrations');
        }
        // The resource's organisation controls its data, including access by
        // existing external collaborators and personal-account sessions.
        $resource = null;
        foreach (['maps' => Map::class, 'missions' => Mission::class, 'routemaps' => RouteMap::class] as $prefix => $model) {
            if (preg_match('#^'.$prefix.'/([^/]+)#', $path, $match)) { $resource = $model::find($match[1]); break; }
        }
        if (!$resource && preg_match('#^reports/([^/]+)#', $path, $match)) {
            $report = \App\Models\Report::find($match[1]);
            if ($report?->map_id) $resource = Map::find($report->map_id);
        }
        if (!$resource && $r->input('map_id')) $resource = Map::find($r->input('map_id'));
        if (!$resource && $r->input('mission_id')) $resource = Mission::find($r->input('mission_id'));
        // The parent map remains authoritative for routes, including legacy
        // rows created by external collaborators before ownership inheritance.
        $resourceOrg = $resource instanceof RouteMap ? $resource->map?->connectOrganization : $resource?->connectOrganization;
        if ($resourceOrg) {
            $internal = ConnectMember::where('organization_id', $resourceOrg->id)->where('user_id', $user->id)->where('status', 'active')->exists();
            if (!$internal && !$service->policy($resourceOrg)['share_external']) return $deny('share_external');
        }
        $permission = null;
        // Stoppen met live locatie delen moet altijd kunnen (privacy): er is geen recht voor nodig,
        // ook niet 'delete' of 'share_location'. Alleen het eigen locatierecord wordt verwijderd; de
        // inactieve-context-blokkade en de share_external-regel van de resource-organisatie blijven gelden.
        $stopSharing = $r->isMethod('DELETE') && preg_match('#^maps/[^/]+/user-locations/stop-sharing$#', $path);
        if (preg_match('#(?:^|/)(?:export|download)(?:[/.]|$)#', $path)) $permission = 'export';
        elseif ($write && (preg_match('#/(?:shares|share)(?:/|$)#', $path))) $permission = 'public_links';
        elseif ($write && str_contains($path, '/collaborators')) {
            $permission = 'share_internal';
            if (in_array($r->method(), ['POST', 'PUT', 'PATCH'])) {
                $orgId = $resourceOrg?->id ?? ($context['organization']['id'] ?? null);
                $targetId = $r->input('user_id') ?? $r->route('userId');
                $email = $r->input('email');
                $query = ConnectMember::where('organization_id', $orgId)->where('status', 'active');
                $internal = $orgId && ($targetId || $email) && $query->where(function ($q) use ($targetId, $email) {
                    if ($targetId) $q->where('user_id', $targetId);
                    elseif ($email) $q->whereRaw('LOWER(email) = ?', [strtolower($email)]);
                })->exists();
                if (!$internal) $permission = 'share_external';
            }
        } elseif ($write && $path === 'user/account/reset') {
            $permission = 'reset_account';
        } elseif ($stopSharing) {
            $permission = null;
        } elseif ($r->isMethod('POST') && preg_match('#^(?:maps/[^/]+/user-locations/update|missions/[^/]+/tracks)$#', $path)) {
            // Live locatie op een kaart en live GPS-tracking in een missie: eigen recht, niet 'create'.
            $permission = 'share_location';
        } elseif ($write && preg_match('#^(maps|routemaps|missions|reports|activities|sites|ogroup-templates|task-templates|exercise-sites|nine-lines|trash|me/media)(/|$)#', $path)) {
            $permission = $r->isMethod('DELETE') ? 'delete' : ($r->isMethod('POST') && !preg_match('#/(restore|reorder|status|sync)(/|$)#', $path) ? 'create' : 'edit');
        }
        if ($write && !$permission && !$stopSharing && $context['managed'] && !preg_match('#^(user/(profile|settings|password|sessions)|login(?:/|$)|logout(?:-all)?$|push-token$|auth/|security/|me/notifications/|weather-alerts/access$|invitations/)#', $path)) {
            $permission = $r->isMethod('DELETE') ? 'delete' : 'edit';
        }
        if ($permission && $context['managed'] && !$service->allows($user, $permission)) return $deny($permission);
        if ($permission && $resourceOrg && (int) ($context['organization']['id'] ?? 0) !== (int) $resourceOrg->id && !$service->policy($resourceOrg)[$permission]) return $deny($permission);
        // Append audit only after a successful mutation; never store request
        // payloads, passwords, locations, files, bindings or tokens.
        $response = $next($r);
        $orgId = $resourceOrg?->id ?? ($context['organization']['id'] ?? null);
        // Live locatie stuurt elke paar seconden een ping; dat zou het audit-log laten
        // overlopen. Per gebruiker + kaart/missie hooguit één regel per uur.
        $auditThis = true;
        if ($permission === 'share_location') {
            try { $auditThis = \Illuminate\Support\Facades\Cache::add('connect_audit_loc:'.$user->id.':'.$path, 1, now()->addHour()); } catch (\Throwable) { $auditThis = true; }
        }
        if ($write && $orgId && $auditThis && $response->getStatusCode() < 400) {
            $service->audit($orgId, $r->method().' '.$r->route()->uri(), $resource ? class_basename($resource) : null, $resource?->id);
        }
        $response->headers->set('X-Milmap-Management', base64_encode(json_encode(['user_id' => $user->id] + $service->context($user))));
        return $response;
    }
}
