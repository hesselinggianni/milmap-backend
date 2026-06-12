<?php

namespace App\Http\Controllers;

use App\Models\UserUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatAttachmentController extends Controller
{
    private const ALLOWED_MIME = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        // Voice notes / push-to-talk clips
        'audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/aac', 'audio/wav', 'audio/x-m4a',
    ];

    // Container formats whose magic bytes are ambiguous: PHP's fileinfo sniffs an
    // audio-only MediaRecorder clip as "video/webm", "video/mp4" or
    // "application/ogg" because the container header is shared with video. We
    // accept these ONLY when the browser-declared type or the file extension
    // confirms audio, so we never silently allow a real video upload.
    private const AMBIGUOUS_AUDIO_CONTAINERS = [
        'video/webm', 'video/x-matroska', 'video/mp4', 'video/ogg', 'application/ogg',
    ];

    private const AUDIO_EXTENSIONS = ['webm', 'ogg', 'oga', 'mp4', 'm4a', 'mp3', 'aac', 'wav'];

    private const MAX_SIZE = 15 * 1024 * 1024; // 15 MB

    public function store(Request $request)
    {
        // Custom messages so users never see the raw "validation.uploaded" key
        // (this app ships no lang/ dir, so untranslated keys would leak through).
        // The implicit `uploaded` rule fires when PHP itself rejects the file —
        // almost always because it exceeded the host's upload_max_filesize.
        $request->validate([
            'file' => ['required', 'file', 'max:15360'],
        ], [
            'file.required' => 'Geen bestand ontvangen.',
            'file.file'     => 'Het geüploade item is geen geldig bestand.',
            'file.uploaded' => 'Uploaden mislukt — het bestand is te groot voor de server of de verbinding viel weg. Probeer een kleiner bestand (max 15 MB).',
            'file.max'      => 'Bestand te groot (max 15 MB).',
        ]);

        $file = $request->file('file');

        $detected = $file->getMimeType();                 // content-sniffed (fileinfo)
        $declared = (string) $file->getClientMimeType();  // browser-sent Content-Type
        $ext      = strtolower($file->getClientOriginalExtension() ?: '');

        $isAllowed = in_array($detected, self::ALLOWED_MIME, true);
        $isAudio   = str_starts_with($detected, 'audio/');

        // Rescue voice notes that fileinfo reports as video/* or application/ogg
        // (audio-only WebM/MP4/Ogg recordings) — but only if the browser or the
        // extension confirms audio, so real video files stay blocked.
        if (!$isAllowed && in_array($detected, self::AMBIGUOUS_AUDIO_CONTAINERS, true)) {
            if (str_starts_with($declared, 'audio/') || in_array($ext, self::AUDIO_EXTENSIONS, true)) {
                $isAllowed = true;
                $isAudio   = true;
            }
        }

        if (!$isAllowed) {
            return response()->json(['message' => 'Bestandstype niet toegestaan.'], 422);
        }

        if ($file->getSize() > self::MAX_SIZE) {
            return response()->json(['message' => 'Bestand te groot (max 15 MB).'], 422);
        }

        $isImage = str_starts_with($detected, 'image/');
        $ext = $ext ?: ($isImage ? 'jpg' : ($isAudio ? 'webm' : 'bin'));
        $filename = Str::uuid() . '.' . strtolower($ext);
        $folder = 'chat/' . date('Y/m');

        Storage::disk('public')->putFileAs($folder, $file, $filename);

        // Opslag-grootboek: chat-bijlagen zijn E2EE en hebben geen eigenaar-
        // record op het bericht zelf, dus leggen we hier een lichte metadata-
        // regel vast zodat StatsController het verbruik kan toerekenen. Best-
        // effort — record() slikt eigen fouten en laat de upload nooit klappen.
        UserUpload::record(
            (int) Auth::id(),
            "{$folder}/{$filename}",
            (int) $file->getSize(),
            $detected,
            'chat',
        );

        $url = Storage::disk('public')->url("{$folder}/{$filename}");

        return response()->json([
            'url'      => $url,
            'filename' => $file->getClientOriginalName(),
            'mime'     => $detected,
            'size'     => $file->getSize(),
            'is_image' => $isImage,
            'is_audio' => $isAudio,
        ], 201);
    }
}
