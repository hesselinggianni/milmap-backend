<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Last online" presence. Touches the authenticated user's `last_seen_at`
 * after the response has been sent (terminate), throttled via the cache so we
 * write at most once per user per THROTTLE_SECONDS. This keeps the hot request
 * path free of an extra write on every authenticated call.
 *
 * Visibility is opt-out per user (settings.show_last_seen) and is enforced when
 * the value is *read* (User::publicLastSeen), so it is always recorded but only
 * ever exposed to others when the owner allows it.
 */
class TrackLastSeen
{
    /** Minimum seconds between presence writes for the same user. */
    protected const THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();
        if (! $user) {
            return;
        }

        // Atomic "once per window": Cache::add only succeeds when the key is
        // absent, so concurrent requests collapse into a single DB write.
        $fresh = Cache::add('last_seen:' . $user->id, 1, self::THROTTLE_SECONDS);
        if (! $fresh) {
            return;
        }

        // Direct query update — avoids bumping updated_at / firing model events.
        DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now()]);
    }
}
