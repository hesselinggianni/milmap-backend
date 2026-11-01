<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\PublicProfile;
use App\Models\PublicRouteComment;
use App\Models\TrailRoute;
use App\Services\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Reacties onder een gepubliceerde route/track, met @handle-tags.
 *
 * Privacy: reageren kan alleen met een eigen openbaar profiel; een reactie
 * toont dus alleen weergavenaam/handle, nooit echte naam of e-mail.
 * Getagde profielen (en de maker) krijgen een push.
 */
class PublicCommentController extends Controller
{
    private const MAX_MENTIONS = 5;

    /** GET /api/v1/profiles/{handle}/{kind}/{id}/comments */
    public function index(string $handle, string $kind, string $id): JsonResponse
    {
        [$type, $subjectId] = $this->subject($handle, $kind, $id);
        $me = (int) optional(auth('sanctum')->user())->id;

        $rows = PublicRouteComment::where('subject_type', $type)->where('subject_id', $subjectId)
            ->orderByDesc('id')->limit(100)->get();

        return response()->json(['comments' => $this->present($rows, $me)]);
    }

    /** POST /api/v1/profiles/{handle}/{kind}/{id}/comments */
    public function store(Request $request, string $handle, string $kind, string $id): JsonResponse
    {
        $user = Auth::user();
        [$type, $subjectId, $owner] = $this->subject($handle, $kind, $id);
        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);
        $body = trim(strip_tags($data['body']));
        abort_if($body === '', 422, 'Lege reactie.');

        $mine = PublicProfile::visible()->where('user_id', $user->id)->first();
        abort_unless($mine, 403, 'Zet eerst je openbare profiel aan om te reageren.');

        $mentioned = $this->resolveMentions($body, (int) $user->id);
        $comment = PublicRouteComment::create([
            'subject_type' => $type, 'subject_id' => $subjectId, 'user_id' => $user->id,
            'body' => $body, 'mentions' => $mentioned->pluck('user_id')->all(),
        ]);

        $link = "/u/{$owner->handle}?open={$type}-{$subjectId}";
        $notified = [];
        foreach ($mentioned as $p) {
            $this->notify((int) $p->user_id, $p->user?->language, 'mention', $mine->publicName(), $body, $link);
            $notified[] = (int) $p->user_id;
        }
        // De maker hoort ook van een reactie, tenzij die al is getagd of zelf reageert.
        if ((int) $owner->user_id !== (int) $user->id && ! in_array((int) $owner->user_id, $notified, true)) {
            $this->notify((int) $owner->user_id, $owner->user?->language, 'comment', $mine->publicName(), $body, $link);
        }

        return response()->json(['comment' => $this->present(collect([$comment]), (int) $user->id)[0]], 201);
    }

    /** DELETE /api/v1/profiles/{handle}/{kind}/{id}/comments/{commentId} */
    public function destroy(string $handle, string $kind, string $id, int $commentId): JsonResponse
    {
        [$type, $subjectId, $owner] = $this->subject($handle, $kind, $id);
        $comment = PublicRouteComment::where('subject_type', $type)->where('subject_id', $subjectId)->findOrFail($commentId);
        abort_unless(in_array((int) Auth::id(), [(int) $comment->user_id, (int) $owner->user_id], true), 403);
        $comment->delete();

        return response()->json(['ok' => true]);
    }

    // ── Reacties op een geïmporteerde OSM-route (geen maker om te verwittigen) ──

    /** GET /api/v1/trail-routes/{slug}/comments */
    public function indexTrail(string $slug): JsonResponse
    {
        $trail = TrailRoute::published()->where('slug', $slug)->firstOrFail();
        $me = (int) optional(auth('sanctum')->user())->id;
        $rows = PublicRouteComment::where('subject_type', 'trail')->where('subject_id', (string) $trail->id)
            ->orderByDesc('id')->limit(100)->get();

        return response()->json(['comments' => $this->present($rows, $me)]);
    }

    /** POST /api/v1/trail-routes/{slug}/comments */
    public function storeTrail(Request $request, string $slug): JsonResponse
    {
        $user = Auth::user();
        $trail = TrailRoute::published()->where('slug', $slug)->firstOrFail();
        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);
        $body = trim(strip_tags($data['body']));
        abort_if($body === '', 422, 'Lege reactie.');

        $mine = PublicProfile::visible()->where('user_id', $user->id)->first();
        abort_unless($mine, 403, 'Zet eerst je openbare profiel aan om te reageren.');

        $mentioned = $this->resolveMentions($body, (int) $user->id);
        $comment = PublicRouteComment::create([
            'subject_type' => 'trail', 'subject_id' => (string) $trail->id, 'user_id' => $user->id,
            'body' => $body, 'mentions' => $mentioned->pluck('user_id')->all(),
        ]);
        $link = "/route/osm/{$trail->slug}";
        foreach ($mentioned as $p) {
            $this->notify((int) $p->user_id, $p->user?->language, 'mention', $mine->publicName(), $body, $link);
        }

        return response()->json(['comment' => $this->present(collect([$comment]), (int) $user->id)[0]], 201);
    }

    /** DELETE /api/v1/trail-routes/{slug}/comments/{commentId} */
    public function destroyTrail(string $slug, int $commentId): JsonResponse
    {
        $trail = TrailRoute::published()->where('slug', $slug)->firstOrFail();
        $comment = PublicRouteComment::where('subject_type', 'trail')->where('subject_id', (string) $trail->id)->findOrFail($commentId);
        abort_unless((int) Auth::id() === (int) $comment->user_id, 403);
        $comment->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array{0:string,1:string,2:PublicProfile} */
    private function subject(string $handle, string $kind, string $id): array
    {
        $profile = PublicProfileController::visibleProfile($handle);
        if ($kind === 'routes') {
            $item = PublicProfileController::findPublicRoute($profile->user_id, $id);
            return ['route', (string) $item->id, $profile];
        }
        abort_unless($kind === 'tracks' && ctype_digit($id), 404);
        $item = PublicProfileController::publicActivitiesQuery($profile->user_id)->findOrFail($id);

        return ['track', (string) $item->id, $profile];
    }

    /** @handles in de tekst → zichtbare profielen (niet jezelf), hooguit MAX_MENTIONS. */
    private function resolveMentions(string $body, int $selfId)
    {
        preg_match_all('/(?<![\w@])@([a-z0-9_]{3,30})/i', $body, $m);
        $handles = array_slice(array_unique(array_map('strtolower', $m[1])), 0, self::MAX_MENTIONS);
        if (! $handles) {
            return collect();
        }

        return PublicProfile::visible()->whereIn('handle', $handles)->where('user_id', '!=', $selfId)->with('user')->get();
    }

    private function notify(int $userId, ?string $lang, string $kind, string $name, string $body, string $link): void
    {
        $locale = in_array($lang, ['nl', 'en'], true) ? $lang : 'en';
        $title = trans("push.public_{$kind}_title", ['name' => $name], $locale);
        $text = mb_substr($body, 0, 120);
        AppNotification::notifyUser($userId, "public_{$kind}", $title, $text, ['route' => $link]);
        try {
            PushSender::alertToUser($userId, $title, $text, ['action' => "public_{$kind}", 'route' => $link]);
        } catch (\Throwable $e) {
            // push is best-effort
        }
    }

    private function present($rows, int $me): array
    {
        $profiles = PublicProfile::whereIn('user_id', $rows->pluck('user_id')->unique())->with('user:id,avatar_path')->get()->keyBy('user_id');

        return $rows->map(function (PublicRouteComment $c) use ($profiles, $me) {
            $p = $profiles[$c->user_id] ?? null;

            return [
                'id' => $c->id,
                'body' => $c->body,
                'handle' => $p && $p->enabled ? $p->handle : null,
                'name' => $p && $p->enabled ? $p->publicName() : null,
                'avatar_url' => $p && $p->enabled ? $p->user?->avatar_url : null,
                'created_at' => $c->created_at?->toIso8601String(),
                'mine' => $me === (int) $c->user_id,
            ];
        })->values()->all();
    }
}
