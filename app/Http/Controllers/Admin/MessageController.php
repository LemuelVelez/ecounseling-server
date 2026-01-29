<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageConversationDeletion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    private function isAdmin(?User $user): bool
    {
        if (! $user) return false;
        $role = strtolower((string) ($user->role ?? ''));
        return str_contains($role, 'admin');
    }

    private function forbid(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden.'], 403);
    }

    /**
     * GET /admin/messages
     * List conversations (one row per conversation_id) using the latest message.
     * Respects per-user conversation deletions (hides if last message is not newer than deleted_at).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();

        if (! $this->isAdmin($actor)) {
            return $this->forbid();
        }

        $perPageRaw = (int) $request->query('per_page', $request->query('limit', 20));
        $perPage = $perPageRaw < 1 ? 20 : ($perPageRaw > 100 ? 100 : $perPageRaw);

        $search = trim((string) $request->query('search', $request->query('q', $request->query('query', ''))));

        // Latest message id per conversation_id (portable across PG/MySQL)
        $latestPerConversation = Message::query()
            ->selectRaw('conversation_id, MAX(id) as last_id')
            ->groupBy('conversation_id');

        $query = Message::query()
            ->joinSub($latestPerConversation, 'last', function ($join) {
                $join->on('messages.id', '=', 'last.last_id');
            })
            // join users for search + name hydration
            ->leftJoin('users as sender_u', 'sender_u.id', '=', 'messages.sender_id')
            ->leftJoin('users as recipient_u', 'recipient_u.id', '=', 'messages.recipient_id')
            ->leftJoin('users as owner_u', 'owner_u.id', '=', 'messages.user_id')
            // deletions for this admin
            ->leftJoin('message_conversation_deletions as mcd', function ($join) use ($actor) {
                $join->on('mcd.conversation_id', '=', 'messages.conversation_id')
                    ->where('mcd.user_id', '=', $actor->id);
            })
            // hide if deleted and the latest message isn't newer than deleted_at
            ->where(function ($q) {
                $q->whereNull('mcd.id')
                  ->orWhereColumn('messages.created_at', '>', 'mcd.deleted_at');
            })
            ->select([
                'messages.*',

                'sender_u.name as sender_user_name',
                'sender_u.email as sender_user_email',
                'sender_u.role as sender_user_role',
                'sender_u.avatar_url as sender_user_avatar',

                'recipient_u.name as recipient_user_name',
                'recipient_u.email as recipient_user_email',
                'recipient_u.role as recipient_user_role',
                'recipient_u.avatar_url as recipient_user_avatar',

                'owner_u.name as owner_user_name',
                'owner_u.email as owner_user_email',
                'owner_u.role as owner_user_role',
                'owner_u.avatar_url as owner_user_avatar',
            ])
            ->orderByDesc('messages.created_at')
            ->orderByDesc('messages.id');

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';

            $query->where(function ($q) use ($like, $search) {
                $q->where('messages.content', 'like', $like)
                  ->orWhere('messages.sender_name', 'like', $like)
                  ->orWhere('sender_u.name', 'like', $like)
                  ->orWhere('sender_u.email', 'like', $like)
                  ->orWhere('recipient_u.name', 'like', $like)
                  ->orWhere('recipient_u.email', 'like', $like)
                  ->orWhere('owner_u.name', 'like', $like)
                  ->orWhere('owner_u.email', 'like', $like);

                if (ctype_digit($search)) {
                    $id = (int) $search;
                    $q->orWhere('messages.sender_id', $id)
                      ->orWhere('messages.recipient_id', $id)
                      ->orWhere('messages.user_id', $id);
                }
            });
        }

        $paginator = $query->paginate($perPage);

        $conversations = collect($paginator->items())->map(function ($m) {
            // Prefer actual user.name; fallback to stored sender_name if user missing
            $senderName = $m->sender_user_name ?: ($m->sender_name ?: null);
            $recipientName = $m->recipient_user_name ?: null;

            return [
                'conversation_id' => $m->conversation_id,

                'last_message' => [
                    'id' => $m->id,
                    'content' => $m->content,
                    'created_at' => optional($m->created_at)->toISOString(),

                    'sender' => $m->sender,
                    'sender_id' => $m->sender_id,
                    'sender_name' => $senderName,
                    'sender_email' => $m->sender_user_email,
                    'sender_role' => $m->sender_user_role,
                    'sender_avatar_url' => $m->sender_user_avatar,

                    'recipient_id' => $m->recipient_id,
                    'recipient_role' => $m->recipient_role,
                    'recipient_name' => $recipientName,
                    'recipient_email' => $m->recipient_user_email,
                    'recipient_user_role' => $m->recipient_user_role,
                    'recipient_avatar_url' => $m->recipient_user_avatar,

                    'owner_user_id' => $m->user_id,
                    'owner_name' => $m->owner_user_name,
                    'owner_email' => $m->owner_user_email,
                    'owner_role' => $m->owner_user_role,
                    'owner_avatar_url' => $m->owner_user_avatar,

                    // existing flags (student/guest vs counselor)
                    'is_read' => (bool) $m->is_read,
                    'counselor_is_read' => (bool) $m->counselor_is_read,
                    'student_read_at' => optional($m->student_read_at)->toISOString(),
                    'counselor_read_at' => optional($m->counselor_read_at)->toISOString(),
                ],
            ];
        })->values();

        return response()->json([
            'message' => 'Fetched conversations.',
            'conversations' => $conversations,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /admin/messages/conversations/{conversationId}
     * Returns messages in a conversation, respecting per-user deletion timestamp.
     */
    public function showConversation(Request $request, string $conversationId): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();

        if (! $this->isAdmin($actor)) {
            return $this->forbid();
        }

        $perPageRaw = (int) $request->query('per_page', 50);
        $perPage = $perPageRaw < 1 ? 50 : ($perPageRaw > 200 ? 200 : $perPageRaw);

        $deletion = MessageConversationDeletion::query()
            ->where('user_id', $actor->id)
            ->where('conversation_id', $conversationId)
            ->first();

        $query = Message::query()
            ->where('conversation_id', $conversationId)
            ->with([
                'senderUser:id,name,email,role,avatar_url',
                'recipientUser:id,name,email,role,avatar_url',
                'user:id,name,email,role,avatar_url',
            ])
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');

        if ($deletion && $deletion->deleted_at) {
            $query->where('created_at', '>', $deletion->deleted_at);
        }

        $paginator = $query->paginate($perPage);

        $messages = collect($paginator->items())->map(function (Message $m) {
            $senderName = $m->senderUser?->name ?: ($m->sender_name ?: null);

            return [
                'id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'content' => $m->content,
                'created_at' => optional($m->created_at)->toISOString(),

                'sender' => $m->sender,
                'sender_id' => $m->sender_id,
                'sender_name' => $senderName,
                'sender_email' => $m->senderUser?->email,
                'sender_role' => $m->senderUser?->role,
                'sender_avatar_url' => $m->senderUser?->avatar_url,

                'recipient_id' => $m->recipient_id,
                'recipient_role' => $m->recipient_role,
                'recipient_name' => $m->recipientUser?->name,
                'recipient_email' => $m->recipientUser?->email,
                'recipient_user_role' => $m->recipientUser?->role,
                'recipient_avatar_url' => $m->recipientUser?->avatar_url,

                'owner_user_id' => $m->user_id,
                'owner_name' => $m->user?->name,
                'owner_email' => $m->user?->email,
                'owner_role' => $m->user?->role,
                'owner_avatar_url' => $m->user?->avatar_url,

                'is_read' => (bool) $m->is_read,
                'counselor_is_read' => (bool) $m->counselor_is_read,
                'student_read_at' => optional($m->student_read_at)->toISOString(),
                'counselor_read_at' => optional($m->counselor_read_at)->toISOString(),
            ];
        })->values();

        return response()->json([
            'message' => 'Fetched conversation.',
            'conversation_id' => $conversationId,
            'deleted_at' => $deletion?->deleted_at ? $deletion->deleted_at->toISOString() : null,
            'messages' => $messages,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * DELETE /admin/messages/conversations/{conversationId}
     * Soft-delete (hide) a conversation for the current admin only.
     *
     * Optional: ?force=1 => hard delete conversation messages for ALL users (admin only).
     */
    public function destroyConversation(Request $request, string $conversationId): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();

        if (! $this->isAdmin($actor)) {
            return $this->forbid();
        }

        $force = (string) $request->query('force', '0');
        $forceHardDelete = in_array(strtolower($force), ['1', 'true', 'yes'], true);

        if ($forceHardDelete) {
            // Hard delete for ALL (dangerous; admin-only)
            Message::query()
                ->where('conversation_id', $conversationId)
                ->delete();

            MessageConversationDeletion::query()
                ->where('conversation_id', $conversationId)
                ->delete();

            return response()->json([
                'message' => 'Conversation deleted permanently.',
                'conversation_id' => $conversationId,
            ]);
        }

        $now = Carbon::now();

        MessageConversationDeletion::query()->updateOrCreate(
            [
                'user_id' => $actor->id,
                'conversation_id' => $conversationId,
            ],
            [
                'deleted_at' => $now,
            ]
        );

        return response()->json([
            'message' => 'Conversation deleted for this admin.',
            'conversation_id' => $conversationId,
            'deleted_at' => $now->toISOString(),
        ]);
    }

    /**
     * PATCH/PUT /admin/messages/{id}
     * Admin edit message content.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();

        if (! $this->isAdmin($actor)) {
            return $this->forbid();
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $message = Message::query()->find($id);

        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $message->content = $validated['content'];
        $message->save();

        return response()->json([
            'message' => 'Message updated.',
            'data' => [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'content' => $message->content,
                'updated_at' => optional($message->updated_at)->toISOString(),
            ],
        ]);
    }

    /**
     * DELETE /admin/messages/{id}
     * Admin hard-delete a single message.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();

        if (! $this->isAdmin($actor)) {
            return $this->forbid();
        }

        $message = Message::query()->find($id);

        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $message->delete();

        return response()->json([
            'message' => 'Message deleted.',
            'id' => $id,
        ]);
    }
}