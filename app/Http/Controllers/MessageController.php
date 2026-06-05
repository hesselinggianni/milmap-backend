<?php

namespace App\Http\Controllers;

use App\Events\MessageCreated;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    /**
     * Paginated message history for a conversation (participant-guarded).
     * Returns newest-last; supports `before` cursor (message id) for paging up.
     */
    public function index(Request $request, $conversationId)
    {
        $userId = Auth::id();

        $conversation = Conversation::findOrFail($conversationId);
        if (! $conversation->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $limit  = min((int) $request->query('limit', 50), 100);
        $before = $request->query('before');
        $after  = $request->query('after');   // poll cursor: only messages AFTER this id

        $messages = Message::where('conversation_id', $conversation->id)
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->when($after,  fn ($q) => $q->where('id', '>', $after))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'messages' => $messages->map(fn (Message $m) => $m->forViewer($userId))->values(),
        ]);
    }

    /**
     * Store an end-to-end-encrypted message and broadcast it.
     * The client sends two sealed boxes: one for the recipient, one for itself.
     * The server never sees plaintext.
     */
    public function store(Request $request, $conversationId)
    {
        $userId = Auth::id();

        $conversation = Conversation::findOrFail($conversationId);
        if (! $conversation->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            // 1-on-1: a single recipient box is required. Group: send `ciphertexts`
            // instead (one box per member) and `ciphertext` may be omitted.
            'ciphertext'      => ['required_without:ciphertexts', 'nullable', 'string', 'max:20000'],
            'ciphertext_self' => ['nullable', 'string', 'max:20000'],
            'ciphertexts'     => ['nullable', 'array'],
            'ciphertexts.*'   => ['string', 'max:20000'],
            'encryption'      => ['nullable', 'in:sealed,none'],
            'type'            => ['nullable', 'in:text,location,mission,image,file,voice'],
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id'       => $userId,
            'ciphertext'      => $data['ciphertext'] ?? null,
            'ciphertext_self' => $data['ciphertext_self'] ?? null,
            'ciphertexts'     => $data['ciphertexts'] ?? null,
            'encryption'      => $data['encryption'] ?? 'sealed',
            'type'            => $data['type'] ?? 'text',
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);

        try {
            broadcast(new MessageCreated($message))->toOthers();
        } catch (\Throwable $e) {
            // Reverb/broadcast not reachable — message is persisted; clients will
            // fetch it on next poll. Log but do NOT let this fail the HTTP response.
            \Illuminate\Support\Facades\Log::warning('[chat] broadcast failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => $message->forViewer($userId),
        ], 201);
    }
}
