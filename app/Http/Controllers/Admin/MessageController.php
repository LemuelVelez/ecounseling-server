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
     * Normalize role strings into stable canonical values.
     * ✅ Includes referral office roles -> referral_user.
     */
    private function normalizeRole(?string $role): string
    {
        $r = strtolower(trim((string) $role));
        $r = str_replace([' ', '-'], ['_', '_'], $r);

        if ($r === '') return '';

        if (str_contains($r, 'counselor') || str_contains($r, 'counsellor') || str_contains($r, 'guidance')) return 'counselor';
        if (str_contains($r, 'admin') || $r === 'administrator' || $r === 'superadmin' || $r === 'super_admin') return 'admin';
        if (str_contains($r, 'student')) return 'student';
        if (str_contains($r, 'guest')) return 'guest';

        // ✅ referral office roles
        if (in_array($r, ['referral_user', 'referraluser', 'referral', 'dean', 'registrar', 'program_chair', 'programchair'], true)) {
            return 'referral_user';
        }

        if ($r === 'system') return 'system';

        return $r;
    }

    /**
     * SQL role normalization for conversation canonicalization.
     * Produces canonical: counselor/admin/student/guest/referral_user/system/other.
     */
    private function roleSql(string $col): string
    {
        $base = "LOWER(REPLACE(REPLACE({$col},'-','_'),' ','_'))";

        return "
            CASE
                WHEN {$base} LIKE '%counselor%' OR {$base} LIKE '%counsellor%' OR {$base} LIKE '%guidance%' THEN 'counselor'
                WHEN {$base} LIKE '%admin%' OR {$base} IN ('administrator','superadmin','super_admin') THEN 'admin'
                WHEN {$base} LIKE '%student%' OR {$base} = 'students' THEN 'student'
                WHEN {$base} LIKE '%guest%' OR {$base} = 'guests' THEN 'guest'
                WHEN {$base} IN ('referral_user','referraluser','referral','referral_users','referralusers','dean','registrar','program_chair','programchair') THEN 'referral_user'
                WHEN {$base} = 'system' THEN 'system'
                ELSE {$base}
            END
        ";
    }

    /**
     * Canonical conversation id generator (PHP-side, used when storing).
     * ✅ Matches canonicalConversationExpr().
     */
    private function canonicalConversationIdForPeer(string $peerRole, $peerId): string
    {
        $role = $this->normalizeRole($peerRole);
        $id = is_string($peerId) ? trim($peerId) : (string) $peerId;
        $id = trim($id);

        if ($id === '') return '';

        // student + guest share one canonical prefix (as designed)
        if ($role === 'student' || $role === 'guest') return "student-{$id}";

        // referral_user canonical uses underscore (frontend-safe)
        if ($role === 'referral_user') return "referral_user-{$id}";

        // general fallback (counselor/admin/other)
        return "{$role}-{$id}";
    }

    /**
     * SQL expression that produces a CANONICAL conversation id across legacy thread ids.
     *
     * ✅ Goal:
     * - Admin inbox groups by the *peer* (non-admin) id, regardless of which side sent the message.
     * - Student/Guest canonical: student-{id}
     * - Referral user canonical: referral_user-{id}
     * - Others: {role}-{id}
     * - Else fallback to messages.conversation_id or msg-{id}
     */
    private function canonicalConversationExpr(string $alias = 'messages'): string
    {
        $t = $alias;

        $senderRole = $this->roleSql("{$t}.sender");
        $recipientRole = $this->roleSql("{$t}.recipient_role");

        return "
            CASE
                -- ✅ Admin is sender => key by recipient (peer)
                WHEN ({$senderRole} = 'admin' AND {$t}.sender_id IS NOT NULL AND {$t}.recipient_id IS NOT NULL)
                    THEN
                        CASE
                            WHEN {$recipientRole} IN ('student','guest') THEN CONCAT('student-', {$t}.recipient_id)
                            WHEN {$recipientRole} = 'referral_user' THEN CONCAT('referral_user-', {$t}.recipient_id)
                            ELSE CONCAT({$recipientRole}, '-', {$t}.recipient_id)
                        END

                -- ✅ Admin is recipient => key by sender (peer)
                WHEN ({$recipientRole} = 'admin' AND {$t}.recipient_id IS NOT NULL AND {$t}.sender_id IS NOT NULL)
                    THEN
                        CASE
                            WHEN {$senderRole} IN ('student','guest') THEN CONCAT('student-', {$t}.sender_id)
                            WHEN {$senderRole} = 'referral_user' THEN CONCAT('referral_user-', {$t}.sender_id)
                            ELSE CONCAT({$senderRole}, '-', {$t}.sender_id)
                        END

                -- Legacy canonicalization (kept for safety)
                WHEN {$senderRole} IN ('student','guest') AND {$t}.sender_id IS NOT NULL
                    THEN CONCAT('student-', {$t}.sender_id)
                WHEN {$recipientRole} IN ('student','guest') AND {$t}.recipient_id IS NOT NULL
                    THEN CONCAT('student-', {$t}.recipient_id)

                WHEN {$senderRole} = 'referral_user' AND {$t}.sender_id IS NOT NULL
                    THEN CONCAT('referral_user-', {$t}.sender_id)
                WHEN {$recipientRole} = 'referral_user' AND {$t}.recipient_id IS NOT NULL
                    THEN CONCAT('referral_user-', {$t}.recipient_id)

                ELSE COALESCE(NULLIF({$t}.conversation_id,''), CONCAT('msg-', {$t}.id))
            END
        ";
    }

    /**
     * Restrict admin inbox to conversations involving THIS admin.
     */
    private function applyAdminParticipationFilter($query, User $actor, string $alias = 'messages'): void
    {
        $senderRole = $this->roleSql("{$alias}.sender");
        $recipientRole = $this->roleSql("{$alias}.recipient_role");

        $query->where(function ($q) use ($actor, $alias, $senderRole, $recipientRole) {
            $q->whereRaw("{$senderRole} = 'admin' AND {$alias}.sender_id = ?", [$actor->id])
              ->orWhereRaw("{$recipientRole} = 'admin' AND {$alias}.recipient_id = ?", [$actor->id]);
        });
    }

    /**
     * Map a Message model to the DTO shape used by your frontend.
     */
    private function messageToDto(Message $m, string $conversationId): array
    {
        $senderName = $m->senderUser?->name ?: ($m->sender_name ?: null);

        $senderNormalized = $this->normalizeRole($m->sender);
        $recipientRoleNormalized = $this->normalizeRole($m->recipient_role);

        return [
            'id' => $m->id,
            'conversation_id' => $conversationId,
            'content' => $m->content,
            'created_at' => optional($m->created_at)->toISOString(),

            'sender' => $senderNormalized,
            'sender_id' => $m->sender_id,
            'sender_name' => $senderName,
            'sender_email' => $m->senderUser?->email,
            'sender_role' => $m->senderUser?->role,
            'sender_avatar_url' => $m->senderUser?->avatar_url,

            'recipient_id' => $m->recipient_id,
            'recipient_role' => $recipientRoleNormalized,
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
    }

    /**
     * GET /admin/messages
     * List conversations (one row per CANONICAL conversation id) using the latest message.
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

        $expr = $this->canonicalConversationExpr('messages');

        // Latest message id per canonical conversation id (only conversations involving THIS admin)
        $latestPerConversation = Message::query()
            ->selectRaw("{$expr} as canonical_conversation_id, MAX(id) as last_id");

        $this->applyAdminParticipationFilter($latestPerConversation, $actor, 'messages');

        $latestPerConversation->groupByRaw($expr);

        $query = Message::query()
            ->joinSub($latestPerConversation, 'last', function ($join) {
                $join->on('messages.id', '=', 'last.last_id');
            })
            ->leftJoin('users as sender_u', 'sender_u.id', '=', 'messages.sender_id')
            ->leftJoin('users as recipient_u', 'recipient_u.id', '=', 'messages.recipient_id')
            ->leftJoin('users as owner_u', 'owner_u.id', '=', 'messages.user_id')
            ->leftJoin('message_conversation_deletions as mcd', function ($join) use ($actor) {
                $join->on('mcd.conversation_id', '=', 'last.canonical_conversation_id')
                    ->where('mcd.user_id', '=', $actor->id);
            })
            ->where(function ($q) {
                $q->whereNull('mcd.id')
                  ->orWhereColumn('messages.created_at', '>', 'mcd.deleted_at');
            })
            ->select([
                'messages.*',
                'last.canonical_conversation_id as canonical_conversation_id',

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
                  ->orWhere('owner_u.email', 'like', $like)
                  ->orWhere('last.canonical_conversation_id', 'like', $like);

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
            $canonicalId = (string) ($m->canonical_conversation_id ?? $m->conversation_id);

            $senderName = $m->sender_user_name ?: ($m->sender_name ?: null);
            $recipientName = $m->recipient_user_name ?: null;

            $senderNormalized = $this->normalizeRole($m->sender);
            $recipientRoleNormalized = $this->normalizeRole($m->recipient_role);

            return [
                'conversation_id' => $canonicalId,
                'last_message' => [
                    'id' => $m->id,
                    'conversation_id' => $canonicalId,
                    'content' => $m->content,
                    'created_at' => optional($m->created_at)->toISOString(),

                    'sender' => $senderNormalized,
                    'sender_id' => $m->sender_id,
                    'sender_name' => $senderName,
                    'sender_email' => $m->sender_user_email,
                    'sender_role' => $m->sender_user_role,
                    'sender_avatar_url' => $m->sender_user_avatar,

                    'recipient_id' => $m->recipient_id,
                    'recipient_role' => $recipientRoleNormalized,
                    'recipient_name' => $recipientName,
                    'recipient_email' => $m->recipient_user_email,
                    'recipient_user_role' => $m->recipient_user_role,
                    'recipient_avatar_url' => $m->recipient_user_avatar,

                    'owner_user_id' => $m->user_id,
                    'owner_name' => $m->owner_user_name,
                    'owner_email' => $m->owner_user_email,
                    'owner_role' => $m->owner_user_role,
                    'owner_avatar_url' => $m->owner_user_avatar,

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
     * ✅ FIX (405): POST /admin/messages
     * Create/send a message as admin.
     *
     * ✅ Enforces canonical conversation_id and normalizes roles.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();

        if (! $this->isAdmin($actor)) {
            return $this->forbid();
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'recipient_id' => ['required'],
            'recipient_role' => ['nullable', 'string', 'max:50'],
            'conversation_id' => ['nullable', 'string', 'max:120'],
        ]);

        $recipientId = $validated['recipient_id'];
        $recipient = User::query()->find($recipientId);

        if (! $recipient) {
            return response()->json(['message' => 'Recipient not found.'], 404);
        }

        $roleInput = $validated['recipient_role'] ?? $recipient->role ?? '';
        $peerRole = $this->normalizeRole((string) $roleInput);

        if ($peerRole === '' || $peerRole === 'system') {
            // last-resort attempt from recipient record
            $peerRole = $this->normalizeRole((string) ($recipient->role ?? ''));
        }

        if ($peerRole === '' || $peerRole === 'system') {
            return response()->json(['message' => 'Invalid recipient role.'], 422);
        }

        $conversationId = $this->canonicalConversationIdForPeer($peerRole, $recipient->id);
        if ($conversationId === '' || strlen($conversationId) > 120) {
            return response()->json(['message' => 'Failed to generate conversation id.'], 422);
        }

        $m = new Message();
        $m->conversation_id = $conversationId;

        $m->content = (string) $validated['content'];

        $m->sender = 'admin';
        $m->sender_id = $actor->id;
        $m->sender_name = (string) ($actor->name ?? 'Admin');

        $m->recipient_id = $recipient->id;
        $m->recipient_role = $peerRole;

        // "owner" field used in your admin payloads—set to the peer so names hydrate nicely
        $m->user_id = $recipient->id;

        // mark unread for recipient
        $m->is_read = false;
        if ($peerRole === 'counselor') {
            $m->counselor_is_read = false;
        }

        $m->save();

        // hydrate relations for response consistency
        $m->load([
            'senderUser:id,name,email,role,avatar_url',
            'recipientUser:id,name,email,role,avatar_url',
            'user:id,name,email,role,avatar_url',
        ]);

        return response()->json([
            'message' => 'Message sent.',
            'messageRecord' => $this->messageToDto($m, $conversationId),
        ], 201);
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

        $conversationId = trim($conversationId);
        if ($conversationId === '' || strlen($conversationId) > 120) {
            return response()->json(['message' => 'Invalid conversation id.'], 422);
        }

        $perPageRaw = (int) $request->query('per_page', 50);
        $perPage = $perPageRaw < 1 ? 50 : ($perPageRaw > 200 ? 200 : $perPageRaw);

        $deletion = MessageConversationDeletion::query()
            ->where('user_id', $actor->id)
            ->where('conversation_id', $conversationId)
            ->first();

        $expr = $this->canonicalConversationExpr('messages');

        $query = Message::query()
            ->whereRaw("{$expr} = ?", [$conversationId]);

        // ✅ only allow viewing conversations involving THIS admin
        $this->applyAdminParticipationFilter($query, $actor, 'messages');

        $query->with([
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

        $messages = collect($paginator->items())->map(function (Message $m) use ($conversationId) {
            return $this->messageToDto($m, $conversationId);
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
     * ✅ NEW: POST /admin/messages/mark-as-read
     * Mark specific message ids as read for the CURRENT admin (recipient).
     *
     * This is what makes "open thread" / "reply" persistently clear unread status.
     */
    public function markAsRead(Request $request): JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();

        if (! $this->isAdmin($actor)) {
            return $this->forbid();
        }

        $validated = $request->validate([
            'message_ids' => ['required', 'array', 'min:1'],
            'message_ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', (array) ($validated['message_ids'] ?? []))));
        $ids = array_values(array_filter($ids, fn ($v) => is_int($v) && $v > 0));

        if (count($ids) === 0) {
            return response()->json([
                'message' => 'No message ids provided.',
                'updated_count' => 0,
            ], 200);
        }

        // Only mark messages where THIS admin is the recipient.
        // We use recipient_id to be resilient even if recipient_role is missing/legacy.
        $updated = Message::query()
            ->whereIn('id', $ids)
            ->whereNotNull('recipient_id')
            ->whereRaw('CAST(recipient_id AS CHAR) = ?', [(string) $actor->id])
            ->update([
                'is_read' => true,
            ]);

        return response()->json([
            'message' => 'Marked messages as read.',
            'updated_count' => (int) $updated,
        ], 200);
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

        $conversationId = trim($conversationId);
        if ($conversationId === '' || strlen($conversationId) > 120) {
            return response()->json(['message' => 'Invalid conversation id.'], 422);
        }

        $force = (string) $request->query('force', '0');
        $forceHardDelete = in_array(strtolower($force), ['1', 'true', 'yes'], true);

        $expr = $this->canonicalConversationExpr('messages');

        if ($forceHardDelete) {
            Message::query()
                ->whereRaw("{$expr} = ?", [$conversationId])
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

        // ✅ Ensure this admin is part of the message
        $senderRole = $this->normalizeRole($message->sender);
        $recipientRole = $this->normalizeRole($message->recipient_role);

        $isMine =
            ($senderRole === 'admin' && (string) $message->sender_id === (string) $actor->id) ||
            ($recipientRole === 'admin' && (string) $message->recipient_id === (string) $actor->id);

        if (! $isMine) {
            return $this->forbid();
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

        // ✅ Ensure this admin is part of the message
        $senderRole = $this->normalizeRole($message->sender);
        $recipientRole = $this->normalizeRole($message->recipient_role);

        $isMine =
            ($senderRole === 'admin' && (string) $message->sender_id === (string) $actor->id) ||
            ($recipientRole === 'admin' && (string) $message->recipient_id === (string) $actor->id);

        if (! $isMine) {
            return $this->forbid();
        }

        $message->delete();

        return response()->json([
            'message' => 'Message deleted.',
            'id' => $id,
        ]);
    }
}