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

        $messages = Message::where('conversation_id', $conversation->id)
            ->when($before, fn ($q) => $q->where('id', '<', $before))
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
            'ciphertext'      => ['required', 'string', 'max:20000'],
            'ciphertext_self' => ['nullable', 'string', 'max:20000'],
            'encryption'      => ['nullable', 'in:sealed,none'],
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id'       => $userId,
            'ciphertext'      => $data['ciphertext'],
            'ciphertext_self' => $data['ciphertext_self'] ?? null,
            'encryption'      => $data['encryption'] ?? 'sealed',
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);

        broadcast(new MessageCreated($message))->toOthers();

        return response()->json([
            'message' => $message->forViewer($userId),
        ], 201);
    }
}
