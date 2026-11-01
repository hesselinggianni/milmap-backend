<?php

namespace App\Http\Controllers;

use App\Models\PublicProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PublicProfileSocialController extends Controller
{
    private function target(string $handle): PublicProfile
    {
        $profile = PublicProfileController::visibleProfile($handle);
        abort_if((int) $profile->user_id === (int) Auth::id(), 422, 'Deze actie kan niet op je eigen profiel.');
        return $profile;
    }

    public function follow(string $handle): JsonResponse
    {
        $target = $this->target($handle);
        abort_if($this->blocked($target->user_id), 403, 'Deze gebruiker is geblokkeerd.');
        DB::table('public_profile_follows')->updateOrInsert(
            ['follower_id' => Auth::id(), 'followed_id' => $target->user_id],
            ['created_at' => now(), 'updated_at' => now()]
        );
        return response()->json(['following' => true, 'followers' => $this->followers($target->user_id)]);
    }

    public function unfollow(string $handle): JsonResponse
    {
        $target = $this->target($handle);
        DB::table('public_profile_follows')->where(['follower_id' => Auth::id(), 'followed_id' => $target->user_id])->delete();
        return response()->json(['following' => false, 'followers' => $this->followers($target->user_id)]);
    }

    public function report(Request $request, string $handle): JsonResponse
    {
        $target = $this->target($handle);
        $data = $request->validate([
            'reason' => ['required', Rule::in(['spam', 'harassment', 'unsafe_content', 'impersonation', 'other'])],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);
        DB::table('public_profile_reports')->insert([
            'reporter_id' => Auth::id(), 'reported_id' => $target->user_id,
            'reason' => $data['reason'], 'details' => $data['details'] ?? null,
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['reported' => true], 201);
    }

    public function block(string $handle): JsonResponse
    {
        $target = $this->target($handle);
        DB::transaction(function () use ($target) {
            DB::table('user_blocks')->updateOrInsert(
                ['blocker_id' => Auth::id(), 'blocked_id' => $target->user_id],
                ['created_at' => now(), 'updated_at' => now()]
            );
            DB::table('public_profile_follows')->where(fn ($q) => $q
                ->where(['follower_id' => Auth::id(), 'followed_id' => $target->user_id])
                ->orWhere(fn ($q) => $q->where(['follower_id' => $target->user_id, 'followed_id' => Auth::id()])))
                ->delete();
        });
        return response()->json(['blocked' => true]);
    }

    public function unblock(string $handle): JsonResponse
    {
        $target = $this->target($handle);
        DB::table('user_blocks')->where(['blocker_id' => Auth::id(), 'blocked_id' => $target->user_id])->delete();
        return response()->json(['blocked' => false]);
    }

    private function blocked(int $id): bool { return DB::table('user_blocks')->where(['blocker_id' => Auth::id(), 'blocked_id' => $id])->exists(); }
    private function followers(int $id): int { return DB::table('public_profile_follows')->where('followed_id', $id)->count(); }
}
