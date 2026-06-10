<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks content-writing requests for view-only accounts.
 *
 * An account is "view-only" only when it was auto-created from an e-mail
 * invitation and has not taken out a paid subscription yet
 * (User::isViewOnly() === view_only && ! subscribed()). Such an invitee may
 * READ everything they were invited to, but must not be able to author or edit
 * premium content (missions, maps, route generation, collaboration, tracks, …).
 *
 * Everyone else — normal registered users and paying subscribers — has
 * view_only = false, so isViewOnly() returns false WITHOUT a DB query and this
 * middleware is a no-op for them. Admins are always exempt.
 *
 * Design: deny-by-default for writes (so no premium write can slip through),
 * with an explicit allow-list of the writes an invitee legitimately needs:
 *   • manage their own account        (user/profile|settings|password, users/{ownId})
 *   • accept / decline invitations     (invitations/*, missions/invitations/*)
 *   • communicate                      (chat/*)
 *   • upgrade — which lifts this gate  (billing/*)
 *   • share their own live position     (maps/{id}/user-locations…)
 *   • give feedback / housekeeping      (bug-reports, search-history)
 */
class EnsureNotViewOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Normal users, subscribers and admins are never gated. For
        // view_only = false this resolves without touching the database.
        if (! $user || ($user->is_admin ?? false) || ! $user->isViewOnly()) {
            return $next($request);
        }

        // Reads never change state — always allowed.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // Strip the API version prefix so the allow-list is independent of it
        // (works for both "api/v1/…" and "v1/…").
        $path = preg_replace('#^(api/)?v1/#', '', ltrim($request->path(), '/'));

        $allowed = [
            'user/profile', 'user/settings', 'user/password',
            'invitations',          // accept / decline / revoke own invites
            'missions/invitations', // accept / decline mission invites
            'chat',                 // full E2EE messaging stack
            'billing',              // subscribe → removes the restriction
            'bug-reports',
            'search-history',
        ];

        foreach ($allowed as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $next($request);
            }
        }

        // Sharing your OWN live position while on a mission (ephemeral, not
        // authored content). Marker writes (maps/{id}/locations) stay blocked.
        if (preg_match('#^maps/\d+/user-locations#', $path)) {
            return $next($request);
        }

        // Editing ONLY your own user record (settings/language saved by id).
        if (preg_match('#^users/(\d+)$#', $path, $m) && (int) $m[1] === (int) $user->id) {
            return $next($request);
        }

        return response()->json([
            'message'   => 'Je account heeft alleen leesrechten. Neem een abonnement om zelf inhoud te maken en te bewerken.',
            'view_only' => true,
        ], 403);
    }
}
