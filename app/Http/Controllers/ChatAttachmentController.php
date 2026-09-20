<?php

namespace App\Http\Controllers;

use App\Models\UserUpload;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatAttachmentController extends Controller
{
    // AES-GCM adds a small authentication tag to the 15 MB client-side limit.
    private const MAX_SIZE = 16 * 1024 * 1024;

    public function store(Request $request)
    {
        // Custom messages so users never see the raw "validation.uploaded" key
        // (this app ships no lang/ dir, so untranslated keys would leak through).
        // The implicit `uploaded` rule fires when PHP itself rejects the file —
        // almost always because it exceeded the host's upload_max_filesize.
        $request->validate([
            'file' => ['required', 'file', 'max:16384'],
            'conversation_id' => ['required', 'uuid', 'exists:conversations,id'],
            'encrypted' => ['required', 'accepted'],
        ], [
            'file.required' => 'Geen bestand ontvangen.',
            'file.file'     => 'Het geüploade item is geen geldig bestand.',
            'file.uploaded' => 'Uploaden mislukt — het bestand is te groot voor de server of de verbinding viel weg. Probeer een kleiner bestand (max 15 MB).',
            'file.max'      => 'Bestand te groot (max 15 MB).',
        ]);

        $file = $request->file('file');
        $conversation = Conversation::findOrFail($request->string('conversation_id'));
        if (! $conversation->hasParticipant((int) Auth::id())) {
            abort(403, 'Geen toegang tot dit gesprek.');
        }

        if ($file->getSize() > self::MAX_SIZE) {
            return response()->json(['message' => 'Versleutelde upload is te groot.'], 422);
        }

        // The browser already encrypted the complete file with AES-GCM. The
        // server intentionally sees only opaque bytes, so MIME/name stay inside
        // the separately E2EE-encrypted message metadata.
        $filename = Str::uuid() . '.bin';
        $folder = 'chat-encrypted/' . date('Y/m');
        $path = Storage::disk('local')->putFileAs($folder, $file, $filename);
        if (! $path) abort(500, 'Versleutelde upload kon niet worden opgeslagen.');

        // Opslag-grootboek: chat-bijlagen zijn E2EE en hebben geen eigenaar-
        // record op het bericht zelf, dus leggen we hier een lichte metadata-
        // regel vast zodat StatsController het verbruik kan toerekenen. Best-
        // effort — record() slikt eigen fouten en laat de upload nooit klappen.
        $upload = UserUpload::record(
            (int) Auth::id(),
            $path,
            (int) $file->getSize(),
            'application/octet-stream',
            'chat',
            'local',
            (string) $conversation->id,
        );
        if (! $upload) {
            Storage::disk('local')->delete($path);
            abort(500, 'Uploadregistratie is mislukt.');
        }

        return response()->json([
            'attachment_id' => $upload->id,
            'url'      => route('chat.attachments.show', ['upload' => $upload->id], false),
            'size'     => $file->getSize(),
            'encrypted' => true,
        ], 201);
    }

    public function show(UserUpload $upload)
    {
        if ($upload->kind !== 'chat' || ! $upload->conversation_id || $upload->disk !== 'local') abort(404);
        $conversation = Conversation::find($upload->conversation_id);
        if (! $conversation || ! $conversation->hasParticipant((int) Auth::id())) abort(404);
        if (! Storage::disk('local')->exists($upload->path)) abort(404);

        return Storage::disk('local')->download($upload->path, 'encrypted-attachment.bin', [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
