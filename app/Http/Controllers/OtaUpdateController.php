<?php

namespace App\Http\Controllers;

use App\Models\OtaBuild;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Self-hosted OTA-updateserver voor de Capacitor-app (@capgo/capacitor-updater).
 *
 * `check()` is het publieke endpoint waar de plugin op elke app-start/resume
 * naartoe POST't (config: plugins.CapacitorUpdater.updateUrl in
 * capacitor.config.json). Contract volgt de self-hosted-vorm van de plugin:
 * de app stuurt platform/channel/huidige bundle-versie mee, wij antwoorden
 * met een nieuwe bundle (url + sha256-checksum) of "geen update".
 *
 * `publish()` is het tegenovergestelde: hier upload de build-pipeline
 * (ota-release.sh in MilMap-Frontend) een nieuwe dist-zip naartoe. Beveiligd
 * met een gedeeld geheim (OtaTokenAuth), net als /deploy/*.
 */
class OtaUpdateController extends Controller
{
    public function check(Request $request)
    {
        $platform = strtolower((string) $request->input('platform', 'all'));
        $channel  = (string) $request->input('channel', 'production');
        $current  = (string) $request->input('version_name', '');

        $build = OtaBuild::query()
            ->where('active', true)
            ->where('channel', $channel)
            ->whereIn('platform', array_unique([$platform, 'all']))
            ->orderByDesc('id')
            ->first();

        if (! $build || $build->version === $current) {
            return response()->json(['message' => 'No update available']);
        }

        return response()->json([
            'version'  => $build->version,
            'url'      => Storage::disk('public')->url($build->bundle_path),
            'checksum' => $build->checksum,
            'mandatory' => $build->mandatory,
        ]);
    }

    public function publish(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'version'  => ['required', 'string', 'max:32'],
            'platform' => ['nullable', 'string', 'in:all,ios,android'],
            'channel'  => ['nullable', 'string', 'max:32'],
            // 'boolean' accepteert alleen 1/0/"1"/"0"/true/false — niet de string
            // "false"/"true" die curl -F meestuurt. 'in' dekt beide vormen.
            'mandatory' => ['nullable', 'in:0,1,true,false'],
            'min_native_version' => ['nullable', 'string', 'max:32'],
            'bundle'   => ['required', 'file', 'mimes:zip'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid publish payload', 'errors' => $validator->errors()], 422);
        }

        $platform = $request->input('platform', 'all');
        $channel  = $request->input('channel', 'production');
        $version  = $request->input('version');
        $file     = $request->file('bundle');

        $name = Str::slug("{$version}-{$channel}") . '-' . Str::random(8) . '.zip';
        $path = $file->storeAs("ota/{$platform}", $name, 'public');
        $checksum = hash_file('sha256', $file->getRealPath());

        $build = OtaBuild::create([
            'platform' => $platform,
            'channel' => $channel,
            'version' => $version,
            'bundle_path' => $path,
            'checksum' => $checksum,
            'size' => $file->getSize(),
            'min_native_version' => $request->input('min_native_version'),
            'mandatory' => (bool) $request->boolean('mandatory'),
            'active' => true,
        ]);

        return response()->json(['build' => $build], 201);
    }
}
