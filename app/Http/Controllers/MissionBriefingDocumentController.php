<?php
namespace App\Http\Controllers;
use App\Models\Mission;
use App\Models\MissionBriefingDocument as Document;
use App\Models\MissionBriefingMedia as Media;
use App\Services\MissionBriefingSources;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class MissionBriefingDocumentController extends Controller {
    private function mission($id, bool $edit = false): Mission {
        abort_unless(config('briefing_builder.enabled'), 404);
        $m = Mission::findOrFail($id);
        abort_unless($m->hasAccess(Auth::id()), 403);
        if ($edit) {
            abort_unless(in_array($m->roleFor(Auth::id()), ['owner', 'editor', 'admin']), 403);
            abort_if($m->locked, 423, 'Mission is locked');
        }
        return $m;
    }
    public function index($id, MissionBriefingSources $sources) {
        $m = $this->mission($id);
        return response()->json(['data' => [
            'documents' => Document::where('mission_id', $id)->latest()->get(['id', 'mission_id', 'title', 'revision', 'updated_at']),
            'sources' => $sources->forMission($m),
            'media' => Media::where('mission_id', $id)->get(),
            'legacy_media' => array_map(fn ($image) => array_diff_key($image, ['source' => true]), $this->legacyImages($m)),
        ]])->header('Cache-Control', 'private, no-store');
    }
    public function show($id, $documentId) {
        $this->mission($id);
        return response()->json(['data' => Document::where('mission_id', $id)->findOrFail($documentId)])->header('Cache-Control', 'private, no-store');
    }
    public function save(Request $request, $id, $documentId, MissionBriefingSources $sources) {
        $this->mission($id, true);
        abort_if(strlen($request->getContent()) > 12 * 1024 * 1024, 413);
        $data = $request->validate([
            'revision' => 'required|integer|min:0',
            'title' => 'required|string|max:200',
            'document' => 'required|array:version,sections,legacy',
            'document.version' => 'required|integer|in:1',
            'document.legacy' => 'nullable|array',
            'document.sections' => 'required|array|min:1|max:60',
            'document.sections.*' => 'required|array:id,title,notes,blocks',
            'document.sections.*.id' => 'required|string|max:80|distinct',
            'document.sections.*.title' => 'present|nullable|string|max:200',
            'document.sections.*.notes' => 'nullable|string|max:10000',
            'document.sections.*.blocks' => 'present|array|max:150',
            'document.sections.*.blocks.*' => 'required|array:id,type,text,subtitle,items,rows,source,snapshot,source_hash,mode,media_id,url,caption,alt,fit,width,rotate,crop,map_ref,route_ids,waypoint_ids,area_ids,extent',
            'document.sections.*.blocks.*.id' => 'required|string|max:80',
            'document.sections.*.blocks.*.type' => ['required', Rule::in(['title','text','list','image','video','table','checklist','map','route','waypoints','weather','team','communication','mission','section','spacer'])],
            'document.sections.*.blocks.*.text' => 'nullable|string|max:100000',
            'document.sections.*.blocks.*.subtitle' => 'nullable|string|max:1000',
            'document.sections.*.blocks.*.caption' => 'nullable|string|max:2000',
            'document.sections.*.blocks.*.alt' => 'nullable|string|max:1000',
            'document.sections.*.blocks.*.items' => 'nullable|array|max:200',
            'document.sections.*.blocks.*.items.*' => 'array:text,done',
            'document.sections.*.blocks.*.items.*.text' => 'present|nullable|string|max:5000',
            'document.sections.*.blocks.*.items.*.done' => 'boolean',
            'document.sections.*.blocks.*.rows' => 'nullable|array|min:1|max:100',
            'document.sections.*.blocks.*.rows.*' => 'array|min:1|max:30',
            'document.sections.*.blocks.*.rows.*.*' => 'nullable|string|max:5000',
            'document.sections.*.blocks.*.mode' => 'nullable|in:live,snapshot',
            'document.sections.*.blocks.*.source' => 'nullable|string|max:100',
            'document.sections.*.blocks.*.source_hash' => 'nullable|string|max:100',
            'document.sections.*.blocks.*.media_id' => ['nullable','uuid', Rule::exists('mission_briefing_media', 'id')->where('mission_id', $id)],
            'document.sections.*.blocks.*.url' => ['nullable','string','max:2500000', function ($a, $v, $fail) {
                // Imported raster data is inert; all remote media must use HTTPS.
                if (!preg_match('~^(https://[^\s]+|data:image/(png|jpeg|webp|gif);base64,[a-zA-Z0-9+/=]+)$~D', $v)) $fail('Unsafe media URL');
            }],
            'document.sections.*.blocks.*.map_ref' => 'nullable|array:id,source,title',
            'document.sections.*.blocks.*.map_ref.id' => 'required_with:document.sections.*.blocks.*.map_ref|string|max:100',
            'document.sections.*.blocks.*.map_ref.source' => 'nullable|in:local,server',
            'document.sections.*.blocks.*.map_ref.title' => 'nullable|string|max:200',
            'document.sections.*.blocks.*.route_ids' => 'nullable|array|max:200',
            'document.sections.*.blocks.*.waypoint_ids' => 'nullable|array|max:200',
            'document.sections.*.blocks.*.area_ids' => 'nullable|array|max:200',
            'document.sections.*.blocks.*.extent' => 'nullable|array:minLon,minLat,maxLon,maxLat',
            'document.sections.*.blocks.*.fit' => 'nullable|in:contain,cover',
            'document.sections.*.blocks.*.width' => 'nullable|integer|min:25|max:100',
            'document.sections.*.blocks.*.rotate' => 'nullable|integer|in:0,90,180,270',
            'document.sections.*.blocks.*.crop' => 'nullable|numeric|min:0|max:100',
        ]);
        foreach ($data['document']['sections'] as &$section) {
            $section['title'] = $section['title'] ?? '';
            foreach ($section['blocks'] as &$block) {
                if ($block['type'] === 'table') abort_unless(!empty($block['rows']), 422, 'A table needs rows');
                if (in_array($block['type'], ['list','checklist'])) abort_unless(isset($block['items']) && is_array($block['items']), 422, 'A list needs items');
            }
            unset($block);
        }
        unset($section);
        // Plain text only; templates never render user HTML. Bound recursive structures.
        $clean = function ($v, $depth = 0) use (&$clean) {
            abort_if($depth > 16, 422, 'Content too deeply nested');
            if (is_string($v)) { abort_if(strlen($v) > 2500000, 422); return strip_tags($v); }
            if (!is_array($v)) return $v;
            abort_if(count($v) > 500, 422, 'Too many items');
            return array_map(fn ($x) => $clean($x, $depth + 1), $v);
        };
        $data = $clean($data);
        return DB::transaction(function () use ($id, $documentId, $data) {
            // Serialize updates including first creation; recheck mission lock inside transaction.
            $m = Mission::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($m->locked, 423);
            $doc = Document::where('mission_id', $id)->whereKey($documentId)->lockForUpdate()->first();
            abort_unless(\Illuminate\Support\Str::isUuid($documentId), 422);
            if (($doc?->revision ?? 0) !== $data['revision']) {
                return response()->json(['message' => 'Briefing gewijzigd op een ander apparaat.', 'current' => $doc], 409);
            }
            if (!$doc) {
                // Never let an ID belonging to another mission become an insert collision.
                abort_if(Document::whereKey($documentId)->exists(), 409);
                $doc = new Document(['id' => $documentId, 'mission_id' => $id]);
            }
            $doc->fill(['title' => $data['title'], 'document' => $data['document'], 'revision' => $data['revision'] + 1])->save();
            return response()->json(['data' => $doc])->header('Cache-Control', 'private, no-store');
        });
    }
    public function upload(Request $request, $id) {
        $this->mission($id, true);
        $request->validate(['file' => 'required|file|max:20480|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime']);
        $file = $request->file('file');
        $path = 'briefing-media/'.$id.'/'.\Illuminate\Support\Str::uuid();
        // Contents as well as metadata encrypted at rest; never stored on the public disk.
        abort_unless(Storage::disk('local')->put($path, Crypt::encryptString(file_get_contents($file->getRealPath()))), 507);
        try {
            $media = Media::create(['mission_id' => $id, 'name' => $file->getClientOriginalName(), 'path' => $path, 'mime' => $file->getMimeType()]);
        } catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
        return response()->json(['data' => $media], 201);
    }
    private function legacyImages(Mission $mission): array {
        $images = [];
        $walk = function ($value) use (&$walk, &$images) {
            if (is_array($value)) { foreach ($value as $child) $walk($child); return; }
            if (!is_string($value)) return;
            if (preg_match('~^data:image/(png|jpeg|webp|gif);base64,[a-zA-Z0-9+/=]+$~D', $value)
                || preg_match('~^https?://[^\s]+\.(png|jpe?g|webp|gif)(\?[^\s]*)?$~iD', $value)) {
                $hash = hash('sha256', $value);
                $images[$hash] = ['id' => $hash, 'name' => str_starts_with($value, 'data:') ? 'Ingesloten missieafbeelding '.(count($images) + 1) : basename(parse_url($value, PHP_URL_PATH)), 'source' => $value];
            }
        };
        $walk($mission->ogroup ?? []);
        return array_values($images);
    }
    public function importMedia(Request $request, $id) {
        $m = $this->mission($id, true);
        $data = $request->validate(['source_id' => 'required|string|size:64']);
        $image = collect($this->legacyImages($m))->firstWhere('id', $data['source_id']);
        abort_unless($image, 404);
        $source = $image['source'];
        if (str_starts_with($source, 'data:')) {
            $bytes = base64_decode(explode(',', $source, 2)[1], true);
        } else {
            // Only copy files from our public disk. Never fetch a submitted URL (SSRF).
            $prefix = rtrim(Storage::disk('public')->url(''), '/').'/';
            abort_unless(str_starts_with($source, $prefix), 422, 'External image must be uploaded separately');
            $path = rawurldecode(explode('?', substr($source, strlen($prefix)), 2)[0]);
            abort_if(str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '\\'), 422);
            abort_unless(Storage::disk('public')->exists($path), 404);
            abort_if(Storage::disk('public')->size($path) > 20 * 1024 * 1024, 413);
            $bytes = Storage::disk('public')->get($path);
        }
        abort_unless($bytes && strlen($bytes) <= 20 * 1024 * 1024, 422);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(in_array($mime, ['image/jpeg','image/png','image/webp','image/gif']), 422);
        $path = 'briefing-media/'.$id.'/'.\Illuminate\Support\Str::uuid();
        abort_unless(Storage::disk('local')->put($path, Crypt::encryptString($bytes)), 507);
        try { $media = Media::create(['mission_id'=>$id,'name'=>$image['name'],'path'=>$path,'mime'=>$mime]); }
        catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
        return response()->json(['data'=>$media],201);
    }
    public function media($id, $mediaId) {
        $this->mission($id);
        $media = Media::where('mission_id', $id)->findOrFail($mediaId);
        abort_unless(Storage::disk('local')->exists($media->path), 404);
        return response(Crypt::decryptString(Storage::disk('local')->get($media->path)))
            ->header('Content-Type', $media->mime)->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff')->header('Content-Disposition', 'inline');
    }
}
