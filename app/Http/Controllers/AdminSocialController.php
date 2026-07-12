<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\SocialPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AdminSocialController extends Controller
{
    private const PLATFORMS = ['pinterest', 'instagram', 'dribbble'];

    /** Lijst met posts (nieuwste eerst) + koppelstatus per platform. */
    public function index()
    {
        $posts = SocialPost::orderByDesc('created_at')->limit(100)->get()
            ->map(fn (SocialPost $p) => $this->present($p));

        $accounts = [];
        foreach (self::PLATFORMS as $platform) {
            $acct = SocialAccount::where('platform', $platform)->first();
            $accounts[$platform] = [
                'connected' => $acct?->isConnected() ?? false,
                'board_id'  => $platform === 'pinterest' ? $acct?->board_id : null,
            ];
        }

        return response()->json(['data' => $posts, 'accounts' => $accounts]);
    }

    /** Nieuwe post opstellen (beeld + tekst + doelplatformen). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => 'nullable|string|max:200',
            'description' => 'nullable|string|max:2000',
            'link'        => 'nullable|url|max:2000',
            'platforms'   => 'required|array|min:1',
            'platforms.*' => 'in:pinterest,instagram,dribbble',
            'image'       => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
        ]);

        $path = $request->file('image')->store('social', 'public');

        $post = SocialPost::create([
            'title'       => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'link'        => $data['link'] ?? null,
            'image_path'  => $path,
            'platforms'   => array_values(array_unique($data['platforms'])),
            'statuses'    => [],
            'created_by'  => Auth::id(),
        ]);

        return response()->json(['data' => $this->present($post)], 201);
    }

    /** Publiceer een post naar alle (of één) gekoppelde platform(en). */
    public function publish(Request $request, int $id, SocialPublisher $publisher)
    {
        $post = SocialPost::findOrFail($id);
        $only = $request->input('platform'); // optioneel: één platform

        $targets = $only ? [$only] : ($post->platforms ?? []);
        $statuses = $post->statuses ?? [];

        foreach ($targets as $platform) {
            if (! in_array($platform, self::PLATFORMS, true)) {
                continue;
            }
            $statuses[$platform] = $publisher->publish($post, $platform);
        }

        $post->statuses = $statuses;
        $post->save();

        return response()->json(['data' => $this->present($post)]);
    }

    public function destroy(int $id)
    {
        $post = SocialPost::findOrFail($id);
        if ($post->image_path) {
            Storage::disk('public')->delete($post->image_path);
        }
        $post->delete();

        return response()->json(['ok' => true]);
    }

    /** Platform-credentials opslaan (nu vooral Pinterest: token + board). */
    public function saveAccount(Request $request, string $platform)
    {
        abort_unless(in_array($platform, self::PLATFORMS, true), 404);

        $data = $request->validate([
            'access_token' => 'nullable|string',
            'board_id'     => 'nullable|string',
        ]);

        $acct = SocialAccount::firstOrNew(['platform' => $platform]);
        if (array_key_exists('access_token', $data) && $data['access_token'] !== null) {
            $acct->access_token = $data['access_token'];
        }
        if (array_key_exists('board_id', $data)) {
            $acct->board_id = $data['board_id'];
        }
        $acct->save();

        return response()->json([
            'ok'        => true,
            'connected' => $acct->isConnected(),
        ]);
    }

    private function present(SocialPost $p): array
    {
        return [
            'id'          => $p->id,
            'title'       => $p->title,
            'description' => $p->description,
            'link'        => $p->link,
            'image_url'   => $p->imageUrl(),
            'platforms'   => $p->platforms ?? [],
            'statuses'    => $p->statuses ?? [],
            'created_at'  => $p->created_at?->toIso8601String(),
        ];
    }
}
