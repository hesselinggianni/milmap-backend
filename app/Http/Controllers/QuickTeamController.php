<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Personal reusable rosters, including people without accounts and devices.
 * Saving a roster never creates memberships, invitations or subscription seats.
 */
class QuickTeamController extends Controller
{
    public function index()
    {
        return response()->json(['teams' => Auth::user()->settings['quick_teams'] ?? []]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'members' => 'required|array|min:1|max:25',
            'members.*.name' => 'required|string|max:120',
            'members.*.user_id' => 'nullable|integer|exists:users,id',
            'members.*.device_id' => 'nullable|integer|min:1',
        ]);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'Geef het team een naam.');
        $members = collect($data['members'])->map(function ($member) {
            $out = ['name' => trim($member['name'])];
            abort_if($out['name'] === '', 422, 'Geef iedere deelnemer een naam.');
            if (!empty($member['user_id'])) $out['user_id'] = (int) $member['user_id'];
            elseif (!empty($member['device_id'])) $out['device_id'] = (int) $member['device_id'];
            return $out;
        })->unique(fn ($m) => isset($m['user_id']) ? 'u:'.$m['user_id']
            : (isset($m['device_id']) ? 'd:'.$m['device_id'] : 'n:'.mb_strtolower($m['name'])))->values()->all();

        $team = DB::transaction(function () use ($name, $members) {
            $user = User::whereKey(Auth::id())->lockForUpdate()->firstOrFail();
            $settings = $user->settings ?? [];
            $teams = $settings['quick_teams'] ?? [];
            $index = collect($teams)->search(fn ($t) => mb_strtolower($t['name']) === mb_strtolower($name));
            abort_if($index === false && count($teams) >= 100, 422, 'Je kunt maximaal 100 teams bewaren.');
            if ($index !== false) {
                $members = collect(array_merge($members, $teams[$index]['members']))
                    ->unique(fn ($m) => isset($m['user_id']) ? 'u:'.$m['user_id']
                        : (isset($m['device_id']) ? 'd:'.$m['device_id'] : 'n:'.mb_strtolower($m['name'])))
                    ->values()->all();
                abort_if(count($members) > 25, 422, 'Een team kan maximaal 25 deelnemers bevatten.');
            }
            // Same name updates the account roster, rather than creating duplicates.
            $team = ['id' => $index === false ? (string) Str::uuid() : $teams[$index]['id'],
                'name' => $name, 'members' => $members, 'updated_at' => now()->toIso8601String()];
            if ($index === false) $teams[] = $team;
            else $teams[$index] = $team;
            $settings['quick_teams'] = array_values($teams);
            $user->settings = $settings;
            $user->save();
            return $team;
        });
        return response()->json(['team' => $team], 201);
    }
}
