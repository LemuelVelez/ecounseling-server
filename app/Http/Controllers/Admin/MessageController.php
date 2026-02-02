<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageConversationDeletion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        if (in_array($r, ['referral_user', 'referraluser', 'referral', 'referral_users', 'referralusers', 'dean', 'registrar', 'program_chair', 'programchair'], true)) {
            return 'referral_user';
        }

        if ($r === 'system') return 'system';

        return $r;
    }

    /**
     * SQL role normalization for conversation canonicalization.
     */
    private function roleSql(string $col): string
    {
        $base = "LOWER(REPLACE(REPLACE({$col},'-','_'),' ','_'))";

        // ✅ Wrap in parentheses so it is always a clean SQL expression
        return "(
            CASE
                WHEN {$base} LIKE '%counselor%' OR {$base} LIKE '%counsellor%' OR {$base} LIKE '%guidance%' THEN 'counselor'
                WHEN {$base} LIKE '%admin%' OR {$base} IN ('administrator','superadmin','super_admin') THEN 'admin'
                WHEN {$base} LIKE '%student%' OR {$base} = 'students' THEN 'student'
                WHEN {$base} LIKE '%guest%' OR {$base} = 'guests' THEN 'guest'
                WHEN {$base} IN ('referral_user','referraluser','referral','referral_users','referralusers','dean','registrar','program_chair','programchair') THEN 'referral_user'
                WHEN {$base} = 'system' THEN 'system'
                ELSE {$base}
            END
        )";
    }

    /**
     * Choose the correct "admin read" column if the DB has one,
     * otherwise fall back to `is_read`.
     */
    private function adminReadColumnName(): string
    {
        try {
            if (Schema::hasColumn('messages', 'admin_is_read')) return 'admin_is_read';
            if (Schema::hasColumn('messages', 'is_read_by_admin')) return 'is_read_by_admin';
            if (Schema::hasColumn('messages', 'read_by_admin')) return 'read_by_admin';
        } catch (\Throwable $e) {
            // ignore
        }

        return 'is_read';
    }

    /**
     * DB-safe CAST target for "read flag" checks.
     * - pgsql/sqlite: TEXT
     * - mysql: CHAR
     */
    private function castTextType(): string
    {
        $driver = (string) DB::getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') return 'TEXT';
        if ($driver === 'sqlsrv') return 'NVARCHAR(10)';
        return 'CHAR';
    }

    /**
     * ✅ DB-safe "unread" predicate that works for:
     * - boolean columns (false/true)
     * - tinyint columns (0/1)
     * - null (treated as unread)
     */
    private function unreadFlagPredicateSql(string $colSql): string
    {
        $cast = $this->castTextType();
        $expr = "COALESCE(CAST({$colSql} AS {$cast}), '0')";
        return "{$expr} IN ('0','false','f')";
    }

    /**
     * Canonical conversation id generator (PHP-side) used when storing.
     */
    private function canonicalConversationIdForPeer(string $peerRole, $peerId): string
    {
        $role = $this->normalizeRole($peerRole);
        $id = is_string($peerId) ? trim($peerId) : (string) $peerId;
        $id = trim($id);

        if ($id === '') return '';

        // student + guest share one canonical prefix
        if ($role === 'student' || $role === 'guest') return "student-{$id}";

        // referral_user canonical uses underscore (frontend-safe)
        if ($role === 'referral_user') return "referral_user-{$id}";

        return "{$role}-{$id}";
    }

    /**
     * ✅ CRITICAL FIX:
     * Canonical conversation id expression FROM THE ADMIN VIEWPOINT.
     */
    private function canonicalConversationExprForAdmin(User $actor, string $alias = 'messages'): string
    {
        $t = $alias;

        $senderRole = $this->roleSql("{$t}.sender");
        $recipientRole = $this->roleSql("{$t}.recipient_role");

        $aid = (int) $actor->id;

        return "
            CASE
                WHEN ({$t}.recipient_id = {$aid} AND {$t}.sender_id IS NOT NULL)
                    THEN
                        CASE
                            WHEN {$senderRole} IN ('student','guest') THEN CONCAT('student-', {$t}.sender_id)
                            WHEN {$senderRole} = 'referral_user' THEN CONCAT('referral_user-', {$t}.sender_id)
                            ELSE CONCAT({$senderRole}, '-', {$t}.sender_id)
                        END

                WHEN ({$t}.sender_id = {$aid} AND {$t}.recipient_id IS NOT NULL)
                    THEN
                        CASE
                            WHEN {$recipientRole} IN ('student','guest') THEN CONCAT('student-', {$t}.recipient_id)
                            WHEN {$recipientRole} = 'referral_user' THEN CONCAT('referral_user-', {$t}.recipient_id)
                            ELSE CONCAT({$recipientRole}, '-', {$t}.recipient_id)
                        END

                WHEN ({$recipientRole} = 'admin' AND {$t}.recipient_id IS NULL AND {$t}.sender_id IS NOT NULL)
                    THEN
                        CASE
                            WHEN {$senderRole} IN ('student','guest') THEN CONCAT('student-', {$t}.sender_id)
                            WHEN {$senderRole} = 'referral_user' THEN CONCAT('referral_user-', {$t}.sender_id)
                            ELSE CONCAT({$senderRole}, '-', {$t}.sender_id)
                        END

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
            // Admin sent
            $q->whereRaw("({$senderRole}) = 'admin' AND {$alias}.sender_id = ?", [$actor->id])

                // Admin received (direct OR broadcast-to-admin)
                ->orWhere(function ($q2) use ($actor, $alias, $recipientRole) {
                    $q2->whereRaw("({$recipientRole}) = 'admin'")
                        ->where(function ($q3) use ($actor, $alias) {
                            $q3->where("{$alias}.recipient_id", "=", $actor->id)
                                ->orWhereNull("{$alias}.recipient_id");
                        });
                })

                // ✅ Robust: if recipient_id == this admin, include it regardless of recipient_role
                ->orWhere(function ($q2) use ($actor, $alias) {
                    $q2->where("{$alias}.recipient_id", "=", $actor->id);
                });
        });
    }

    /**
     * Apply per-user conversation delete/hide logic.
     */
    private function applyConversationDeletionFilter($query, User $actor, string $conversationExpr, string $alias = 'messages'): void
    {
        $query->leftJoin('message_conversation_deletions as mcd', function ($join) use ($actor, $conversationExpr) {
            $join->on(DB::raw($conversationExpr), '=', 'mcd.conversation_id')
                ->where('mcd.user_id', '=', (int) $actor->id);
        });

        $query->where(function ($q) use ($alias) {
            $q->whereNull('mcd.deleted_at')
                ->orWhereColumn("{$alias}.created_at", '>', 'mcd.deleted_at');
        });
    }

    private function messagesHasSoftDeletes(): bool
    {
        try {
            return Schema::hasColumn('messages', 'deleted_at');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Determine "owner/peer" (the non-admin side) for admin UI.
     */
    private function computeOwnerForAdmin(User $actor, Message $m): array
    {
        $senderRole = $this->normalizeRole($m->sender);
        $recipientRole = $this->normalizeRole($m->recipient_role);

        $actorId = (string) $actor->id;
        $senderId = $m->sender_id != null ? (string) $m->sender_id : null;
        $recipientId = $m->recipient_id != null ? (string) $m->recipient_id : null;

        // If actor sent, peer is recipient
        if ($senderId && $senderId === $actorId) {
            $peerId = $recipientId;
            $peerRole = $recipientRole;
            $peerUser = $m->recipientUser;
            $peerName = $peerUser?->name ?? $m->recipient_name ?? null;

            return [$peerId, $peerRole, $peerUser, $peerName];
        }

        // If actor received (or broadcast-to-admin), peer is sender
        $peerId = $senderId;
        $peerRole = $senderRole;
        $peerUser = $m->senderUser;
        $peerName = $peerUser?->name ?? $m->sender_name ?? null;

        return [$peerId, $peerRole, $peerUser, $peerName];
    }

    /**
     * Map Message model to DTO shape expected by frontend.
     */
    private function messageToDto(User $actor, Message $m, string $conversationId): array
    {
        [$ownerId, $ownerRole, $ownerUser, $ownerName] = $this->computeOwnerForAdmin($actor, $m);

        $senderName = $m->senderUser?->name ?: ($m->sender_name ?: null);

        return [
            'id' => $m->id,
            'conversation_id' => $conversationId,
            'content' => $m->content,
            'created_at' => optional($m->created_at)->toISOString(),
            'updated_at' => optional($m->updated_at)->toISOString(),

            'sender' => $this->normalizeRole($m->sender),
            'sender_id' => $m->sender_id,
            'sender_name' => $senderName,
            'sender_email' => $m->senderUser?->email,
            'sender_role' => $m->senderUser?->role,
            'sender_avatar_url' => $m->senderUser?->avatar_url,

            'recipient_id' => $m->recipient_id,
            'recipient_role' => $this->normalizeRole($m->recipient_role),
            'recipient_name' => $m->recipientUser?->name ?? $m->recipient_name,
            'recipient_email' => $m->recipientUser?->email,
            'recipient_user_role' => $m->recipientUser?->role,
            'recipient_avatar_url' => $m->recipientUser?->avatar_url,

            'owner_user_id' => $ownerId,
            'owner_name' => $ownerName,
            'owner_email' => $ownerUser?->email,
            'owner_role' => $ownerUser?->role ?? $ownerRole,
            'owner_avatar_url' => $ownerUser?->avatar_url,

            'is_read' => $m->is_read,
            'counselor_is_read' => $m->counselor_is_read ?? null,

            'admin_is_read' => $m->admin_is_read ?? null,
            'is_read_by_admin' => $m->is_read_by_admin ?? null,
            'read_by_admin' => $m->read_by_admin ?? null,
        ];
    }

    /**
     * GET /admin/messages
     *
     * ✅ FIX:
     * - avoids window functions (ROW_NUMBER / SUM OVER)
     * - uses GROUP BY conversation + MAX(last_id) + SUM(unread_case)
     * - unread check is DB-safe (boolean/int/null)
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->isAdmin($actor)) return $this->forbid();

        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min(200, $perPage));

        $search = trim((string) $request->query('search', ''));

        $conversationExpr = $this->canonicalConversationExprForAdmin($actor, 'messages');

        $readCol = $this->adminReadColumnName();
        $readColSql = "messages.{$readCol}";

        $recipientRoleSql = $this->roleSql('messages.recipient_role');

        $unreadPredicate = $this->unreadFlagPredicateSql($readColSql);

        $uid = (int) $actor->id;

        // unread case: addressed to this admin OR broadcast admin AND not read
        $unreadCaseSql = "
            CASE
                WHEN (
                    (
                        messages.recipient_id = {$uid}
                        OR (messages.recipient_id IS NULL AND ({$recipientRoleSql}) = 'admin')
                    )
                    AND ({$unreadPredicate})
                ) THEN 1 ELSE 0 END
        ";

        $q = DB::table('messages')
            ->selectRaw("{$conversationExpr} as conversation_id")
            ->selectRaw("MAX(messages.id) as last_id")
            ->selectRaw("MAX(messages.created_at) as last_created_at")
            ->selectRaw("SUM({$unreadCaseSql}) as unread_count");

        // exclude soft-deleted messages if the column exists (DB::table doesn't auto-apply SoftDeletes)
        if ($this->messagesHasSoftDeletes()) {
            $q->whereNull('messages.deleted_at');
        }

        // participation + deletions
        $this->applyAdminParticipationFilter($q, $actor, 'messages');
        $this->applyConversationDeletionFilter($q, $actor, $conversationExpr, 'messages');

        // Optional search (best-effort)
        if ($search !== '') {
            $q->leftJoin('users as su', 'messages.sender_id', '=', 'su.id');
            $q->leftJoin('users as ru', 'messages.recipient_id', '=', 'ru.id');

            $like = '%' . str_replace(['%','_'], ['\%','\_'], $search) . '%';

            $q->where(function ($w) use ($like, $search) {
                $w->where('messages.content', 'like', $like)
                    ->orWhere('messages.sender_name', 'like', $like)
                    ->orWhere('messages.recipient_name', 'like', $like)
                    ->orWhere('su.name', 'like', $like)
                    ->orWhere('ru.name', 'like', $like);

                if (ctype_digit($search)) {
                    $w->orWhere('messages.id', '=', (int) $search)
                      ->orWhere('messages.sender_id', '=', (int) $search)
                      ->orWhere('messages.recipient_id', '=', (int) $search);
                }
            });
        }

        $q->groupBy(DB::raw($conversationExpr))
          ->orderByDesc('last_created_at')
          ->orderByDesc('last_id');

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        $lastIds = [];
        foreach ($paginator->items() as $row) {
            if (isset($row->last_id)) $lastIds[] = (int) $row->last_id;
        }
        $lastIds = array_values(array_unique(array_filter($lastIds)));

        $messages = Message::query()
            ->with([
                'senderUser:id,name,email,role,avatar_url',
                'recipientUser:id,name,email,role,avatar_url',
            ])
            ->whereIn('id', $lastIds)
            ->get()
            ->keyBy('id');

        $conversations = [];
        foreach ($paginator->items() as $row) {
            $conversationId = (string) ($row->conversation_id ?? '');
            if ($conversationId === '') continue;

            $msg = $messages->get((int) ($row->last_id ?? 0));
            if (! $msg) continue;

            $unreadCount = (int) ($row->unread_count ?? 0);

            $conversations[] = [
                'conversation_id' => $conversationId,
                'last_message' => $this->messageToDto($actor, $msg, $conversationId),
                'unread_count' => $unreadCount,
                'has_unread' => $unreadCount > 0,
            ];
        }

        return response()->json([
            'message' => 'Fetched admin conversations.',
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
     * ✅ Route compatibility:
     * routes/web.php calls showConversation(), so keep it.
     */
    public function showConversation(string $conversationId, Request $request): JsonResponse
    {
        return $this->conversation($conversationId, $request);
    }

    /**
     * GET /admin/messages/conversations/{conversationId}
     */
    public function conversation(string $conversationId, Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->isAdmin($actor)) return $this->forbid();

        $conversationId = trim((string) $conversationId);
        if ($conversationId === '') {
            return response()->json(['message' => 'conversation_id is required.'], 422);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', 200);
        $perPage = max(1, min(500, $perPage));

        $conversationExpr = $this->canonicalConversationExprForAdmin($actor, 'messages');

        $q = Message::query()
            ->from('messages')
            ->select('messages.*')
            ->with([
                'senderUser:id,name,email,role,avatar_url',
                'recipientUser:id,name,email,role,avatar_url',
            ]);

        // participation + deletion + conversation filter
        $this->applyAdminParticipationFilter($q, $actor, 'messages');
        $this->applyConversationDeletionFilter($q, $actor, $conversationExpr, 'messages');

        $q->whereRaw("({$conversationExpr}) = ?", [$conversationId]);
        $q->orderBy('messages.created_at', 'asc')->orderBy('messages.id', 'asc');

        $p = $q->paginate($perPage, ['*'], 'page', $page);

        $out = [];
        foreach ($p->items() as $m) {
            $out[] = $this->messageToDto($actor, $m, $conversationId);
        }

        return response()->json([
            'message' => 'Fetched conversation messages.',
            'conversation_id' => $conversationId,
            'deleted_at' => null,
            'messages' => $out,
            'pagination' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'last_page' => $p->lastPage(),
            ],
        ]);
    }

    /**
     * POST /admin/messages
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->isAdmin($actor)) return $this->forbid();

        $content = trim((string) $request->input('content', ''));
        $recipientId = $request->input('recipient_id');
        $recipientRoleRaw = (string) $request->input('recipient_role', '');
        $recipientRole = $this->normalizeRole($recipientRoleRaw);

        if ($content === '') {
            return response()->json(['message' => 'content is required.'], 422);
        }
        if ($recipientId === null || trim((string) $recipientId) === '') {
            return response()->json(['message' => 'recipient_id is required.'], 422);
        }
        if ($recipientRole === '') {
            return response()->json(['message' => 'recipient_role is required.'], 422);
        }

        $conversationId = $this->canonicalConversationIdForPeer($recipientRole, $recipientId);

        $m = new Message();
        $m->content = $content;

        // Sender = admin
        $m->sender = 'admin';
        $m->sender_id = $actor->id;
        $m->sender_name = $actor->name ?? 'Admin';

        // Recipient
        $m->recipient_id = $recipientId;
        $m->recipient_role = $recipientRole;

        $m->conversation_id = $conversationId;

        if (in_array($recipientRole, ['student', 'guest'], true)) {
            $m->user_id = $recipientId;
        }

        $m->save();

        $m->load([
            'senderUser:id,name,email,role,avatar_url',
            'recipientUser:id,name,email,role,avatar_url',
        ]);

        return response()->json([
            'message' => 'Message sent.',
            'messageRecord' => $this->messageToDto($actor, $m, $conversationId),
        ], 201);
    }

    /**
     * PATCH /admin/messages/{id}
     */
    public function update($id, Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->isAdmin($actor)) return $this->forbid();

        $content = trim((string) $request->input('content', ''));
        if ($content === '') {
            return response()->json(['message' => 'content is required.'], 422);
        }

        $m = Message::query()->find($id);
        if (! $m) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        if ((string) ($m->sender_id ?? '') !== (string) $actor->id || $this->normalizeRole($m->sender) !== 'admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $m->content = $content;
        $m->updated_at = Carbon::now();
        $m->save();

        return response()->json([
            'message' => 'Message updated.',
            'data' => [
                'id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'content' => $m->content,
                'updated_at' => optional($m->updated_at)->toISOString(),
            ],
        ]);
    }

    /**
     * DELETE /admin/messages/{id}
     */
    public function destroy($id, Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->isAdmin($actor)) return $this->forbid();

        $m = Message::query()->find($id);
        if (! $m) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $m->delete();

        return response()->json([
            'message' => 'Message deleted.',
            'id' => $id,
        ]);
    }

    /**
     * ✅ Route compatibility:
     * routes/web.php calls destroyConversation(), so keep it.
     */
    public function destroyConversation(string $conversationId, Request $request): JsonResponse
    {
        return $this->deleteConversation($conversationId, $request);
    }

    /**
     * DELETE /admin/messages/conversations/{conversationId}
     */
    public function deleteConversation(string $conversationId, Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->isAdmin($actor)) return $this->forbid();

        $conversationId = trim((string) $conversationId);
        if ($conversationId === '') {
            return response()->json(['message' => 'conversation_id is required.'], 422);
        }

        $now = Carbon::now();

        $row = MessageConversationDeletion::query()->updateOrCreate(
            [
                'user_id' => (int) $actor->id,
                'conversation_id' => $conversationId,
            ],
            [
                'deleted_at' => $now,
            ],
        );

        return response()->json([
            'message' => 'Conversation deleted.',
            'conversation_id' => $conversationId,
            'deleted_at' => optional($row->deleted_at)->toISOString(),
        ]);
    }

    /**
     * POST /admin/messages/mark-as-read
     *
     * ✅ FIX: set read flag using boolean TRUE (DB-safe for boolean/tinyint).
     */
    public function markAsRead(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $this->isAdmin($actor)) return $this->forbid();

        $ids = $request->input('message_ids', []);
        if (! is_array($ids) || count($ids) === 0) {
            return response()->json(['message' => 'message_ids is required.'], 422);
        }

        $clean = [];
        foreach ($ids as $v) {
            $s = trim((string) $v);
            if ($s === '' || ! ctype_digit($s)) continue;
            $clean[] = (int) $s;
        }
        $clean = array_values(array_unique($clean));

        if (count($clean) === 0) {
            return response()->json(['message' => 'message_ids is required.'], 422);
        }

        $readCol = $this->adminReadColumnName();
        $recipientRoleSql = $this->roleSql('recipient_role');

        $updated = Message::query()
            ->whereIn('id', $clean)
            ->where(function ($q) use ($actor, $recipientRoleSql) {
                $q->where('recipient_id', '=', $actor->id)
                  ->orWhere(function ($q2) use ($recipientRoleSql) {
                      $q2->whereNull('recipient_id')
                         ->whereRaw("({$recipientRoleSql}) = 'admin'");
                  });
            })
            ->update([
                $readCol => true, // ✅ important
                'updated_at' => Carbon::now(),
            ]);

        return response()->json([
            'message' => 'Marked messages as read.',
            'updated_count' => (int) $updated,
        ]);
    }
}