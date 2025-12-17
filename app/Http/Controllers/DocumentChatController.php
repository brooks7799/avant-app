<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentChat;
use App\Services\AI\DocumentChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentChatController extends Controller
{
    public function __construct(
        protected DocumentChatService $chatService,
    ) {}

    /**
     * Get current chat and list of all chat sessions for a document.
     */
    public function index(Document $document): JsonResponse
    {
        $version = $document->currentVersion;

        if (! $version) {
            return response()->json(['error' => 'No document version available'], 404);
        }

        // Get all chat sessions for this document version and user
        $allChats = DocumentChat::where('document_version_id', $version->id)
            ->where('user_id', Auth::id())
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn ($chat) => [
                'id' => $chat->id,
                'title' => $chat->title ?: 'New Chat',
                'message_count' => $chat->messages()->count(),
                'last_message_at' => $chat->last_message_at?->toISOString(),
                'created_at' => $chat->created_at->toISOString(),
            ]);

        // Get or create current chat session
        $currentChat = $this->chatService->getOrCreateChat($version, Auth::user());

        return response()->json([
            'chat_id' => $currentChat->id,
            'title' => $currentChat->title,
            'messages' => $currentChat->messages->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'metadata' => $m->metadata,
                'created_at' => $m->created_at->toISOString(),
            ]),
            'all_chats' => $allChats,
        ]);
    }

    /**
     * Load a specific chat session.
     */
    public function show(Document $document, DocumentChat $chat): JsonResponse
    {
        // Verify the chat belongs to this user
        if ($chat->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'chat_id' => $chat->id,
            'title' => $chat->title,
            'messages' => $chat->messages->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'metadata' => $m->metadata,
                'created_at' => $m->created_at->toISOString(),
            ]),
        ]);
    }

    /**
     * Create a new chat session.
     */
    public function store(Document $document): JsonResponse
    {
        $version = $document->currentVersion;

        if (! $version) {
            return response()->json(['error' => 'No document version available'], 404);
        }

        $chat = DocumentChat::create([
            'document_version_id' => $version->id,
            'user_id' => Auth::id(),
            'last_message_at' => now(),
        ]);

        return response()->json([
            'chat_id' => $chat->id,
            'title' => null,
            'messages' => [],
        ]);
    }

    /**
     * Send a message and stream the response.
     */
    public function send(Request $request, Document $document): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $version = $document->currentVersion;

        if (! $version) {
            return response()->json(['error' => 'No document version available'], 404);
        }

        $chat = $this->chatService->getOrCreateChat($version, Auth::user());

        return new StreamedResponse(function () use ($chat, $validated) {
            $generator = $this->chatService->streamResponse($chat, $validated['message']);

            foreach ($generator as $chunk) {
                echo 'data: '.json_encode(['content' => $chunk])."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            echo "data: [DONE]\n\n";

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Clear chat history.
     */
    public function clear(Document $document): JsonResponse
    {
        $version = $document->currentVersion;

        if (! $version) {
            return response()->json(['error' => 'No document version available'], 404);
        }

        $chat = DocumentChat::where('document_version_id', $version->id)
            ->where('user_id', Auth::id())
            ->first();

        if ($chat) {
            $chat->messages()->delete();
            $chat->update(['title' => null, 'last_message_at' => null]);
        }

        return response()->json(['success' => true]);
    }
}
