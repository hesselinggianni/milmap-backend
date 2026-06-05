<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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

    private const MAX_SIZE = 15 * 1024 * 1024; // 15 MB

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:15360'],
        ]);

        $file = $request->file('file');

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME)) {
            return response()->json(['message' => 'Bestandstype niet toegestaan.'], 422);
        }

        if ($file->getSize() > self::MAX_SIZE) {
            return response()->json(['message' => 'Bestand te groot (max 15 MB).'], 422);
        }

        $mime    = $file->getMimeType();
        $isImage = str_starts_with($mime, 'image/');
        $isAudio = str_starts_with($mime, 'audio/');
        $ext = $file->getClientOriginalExtension()
            ?: ($isImage ? 'jpg' : ($isAudio ? 'webm' : 'bin'));
        $filename = Str::uuid() . '.' . strtolower($ext);
        $folder = 'chat/' . date('Y/m');

        Storage::disk('public')->putFileAs($folder, $file, $filename);

        $url = Storage::disk('public')->url("{$folder}/{$filename}");

        return response()->json([
            'url'      => $url,
            'filename' => $file->getClientOriginalName(),
            'mime'     => $mime,
            'size'     => $file->getSize(),
            'is_image' => $isImage,
            'is_audio' => $isAudio,
        ], 201);
    }
}
