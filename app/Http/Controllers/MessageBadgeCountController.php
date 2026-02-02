<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MessageBadgeCountController extends Controller
{
    private static function isCounselor(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));

        return str_contains($role, 'counselor')
            || str_contains($role, 'counsellor')
            || str_contains($role, 'guidance');
    }

    private static function isAdmin(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));

        return str_contains($role, 'admin');
    }

    private static function isReferralUser(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));

        return str_contains($role, 'referral_user')
            || str_contains($role, 'referral user')
            || str_contains($role, 'referral-user')
            || str_contains($role, 'dean')
            || str_contains($role, 'registrar')
            || str_contains($role, 'program chair')
            || str_contains($role, 'program_chair')
            || str_contains($role, 'programchair')
            || str_contains($role, 'chair');
    }

    private static function normalizeRoleSql(string $col): string
    {
        $base = "LOWER(REPLACE(REPLACE({$col},'-','_'),' ','_'))";

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

    private static function adminReadColumnName(): string
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

    private static function castTextType(): string
    {
        $driver = (string) DB::getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') return 'TEXT';
        if ($driver === 'sqlsrv') return 'NVARCHAR(10)';
        return 'CHAR';
    }

    /**
     * ✅ DB-safe unread predicate (works for boolean/int/null).
     */
    private static function unreadFlagPredicateSql(string $colSql): string
    {
        $cast = self::castTextType();
        $expr = "COALESCE(CAST({$colSql} AS {$cast}), '0')";
        return "{$expr} IN ('0','false','f')";
    }

    /**
     * ✅ IMPORTANT FIX:
     * For ADMIN badge counts, do NOT rely on "latest message is unread".
     * Count conversations that have ANY unread message addressed to admin,
     * even if admin replied last.
     */
    private static function canonicalConversationExprForAdmin(User $user, string $alias = 'messages'): string
    {
        $t = $alias;

        $senderRole = self::normalizeRoleSql("{$t}.sender");
        $recipientRole = self::normalizeRoleSql("{$t}.recipient_role");

        $aid = (int) $user->id;

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

    public static function unreadConversationCountFor(User $user): int
    {
        if (self::isCounselor($user)) {
            return self::unreadConversationCountForCounselor($user);
        }

        if (self::isAdmin($user)) {
            return self::unreadConversationCountForAdmin($user);
        }

        if (self::isReferralUser($user)) {
            return self::unreadConversationCountForReferralUser($user);
        }

        return self::unreadConversationCountForStudentOrGuest($user);
    }

    public function unreadConversationsCount(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $count = self::unreadConversationCountFor($user);

        return response()->json([
            'message' => 'Fetched unread message badge count.',
            'unread_conversations' => $count,
            'counts' => [
                'unread_conversations' => $count,
            ],
        ]);
    }

    private static function unreadConversationCountForCounselor(User $user): int
    {
        $userId = (int) $user->id;

        $effectiveConversationIdSql = "COALESCE(NULLIF(messages.conversation_id,''), CONCAT('student-', messages.user_id))";

        $base = DB::table('messages')
            ->leftJoin('message_conversation_deletions as mcd', function ($join) use ($userId, $effectiveConversationIdSql) {
                $join->on(DB::raw($effectiveConversationIdSql), '=', 'mcd.conversation_id')
                    ->where('mcd.user_id', '=', $userId);
            })
            ->where(function ($q) use ($userId) {
                $q->where(function ($q2) use ($userId) {
                    $q2->where('messages.recipient_role', 'counselor')
                        ->where(function ($q3) use ($userId) {
                            $q3->whereNull('messages.recipient_id')
                                ->orWhere('messages.recipient_id', '=', $userId);
                        });
                })
                ->orWhere(function ($q2) {
                    $q2->whereNotNull('messages.conversation_id')
                        ->where('messages.conversation_id', 'like', 'student-%');
                })
                ->orWhere(function ($q2) use ($userId) {
                    $q2->where('messages.sender', 'counselor')
                        ->where('messages.sender_id', '=', $userId);
                });
            })
            ->where(function ($q) {
                $q->whereNull('mcd.deleted_at')
                    ->orWhereColumn('messages.created_at', '>', 'mcd.deleted_at');
            });

        $ranked = $base->select([
            'messages.id',
            DB::raw($effectiveConversationIdSql . ' as conversation_id'),
            'messages.sender',
            'messages.recipient_role',
            'messages.recipient_id',
            'messages.is_read',
            'messages.counselor_is_read',
            'messages.created_at',
            DB::raw("ROW_NUMBER() OVER (PARTITION BY {$effectiveConversationIdSql} ORDER BY messages.created_at DESC, messages.id DESC) as rn"),
        ]);

        $latestPerConversation = DB::query()
            ->fromSub($ranked, 't')
            ->where('t.rn', '=', 1);

        $isUnreadCounselor = self::unreadFlagPredicateSql('t.counselor_is_read');
        $isUnreadLegacy = self::unreadFlagPredicateSql('t.is_read');

        $count = $latestPerConversation
            ->where('t.recipient_role', '=', 'counselor')
            ->where(function ($q) use ($userId) {
                $q->whereNull('t.recipient_id')
                    ->orWhere('t.recipient_id', '=', $userId);
            })
            ->whereRaw("({$isUnreadCounselor}) AND ({$isUnreadLegacy})")
            ->count();

        return (int) $count;
    }

    /**
     * ✅ FIXED ADMIN COUNTER:
     * Count conversations where there exists ANY unread message addressed to this admin.
     */
    private static function unreadConversationCountForAdmin(User $user): int
    {
        $uid = (int) $user->id;

        $conversationExpr = self::canonicalConversationExprForAdmin($user, 'messages');

        $readCol = self::adminReadColumnName();
        $readColSql = "messages.{$readCol}";

        $recipientRoleSql = self::normalizeRoleSql('messages.recipient_role');

        $base = DB::table('messages')
            ->leftJoin('message_conversation_deletions as mcd', function ($join) use ($uid, $conversationExpr) {
                $join->on(DB::raw($conversationExpr), '=', 'mcd.conversation_id')
                    ->where('mcd.user_id', '=', $uid);
            })
            ->where(function ($q) use ($uid, $recipientRoleSql) {
                $q->where('messages.sender_id', '=', $uid)
                    ->orWhere('messages.recipient_id', '=', $uid)
                    ->orWhere(function ($q2) use ($recipientRoleSql) {
                        $q2->whereNull('messages.recipient_id')
                            ->whereRaw("({$recipientRoleSql}) = 'admin'");
                    });
            })
            ->where(function ($q) {
                $q->whereNull('mcd.deleted_at')
                    ->orWhereColumn('messages.created_at', '>', 'mcd.deleted_at');
            });

        $isUnread = self::unreadFlagPredicateSql($readColSql);

        $unreadCase = DB::raw("
            CASE
                WHEN (
                    (
                        messages.recipient_id = {$uid}
                        OR (messages.recipient_id IS NULL AND ({$recipientRoleSql}) = 'admin')
                    )
                    AND ({$isUnread})
                ) THEN 1 ELSE 0 END
        ");

        $count = DB::query()
            ->fromSub(
                $base->select([
                    DB::raw("{$conversationExpr} as conversation_id"),
                    $unreadCase . " as unread_flag",
                ]),
                't'
            )
            ->groupBy('t.conversation_id')
            ->havingRaw('SUM(t.unread_flag) > 0')
            ->count();

        return (int) $count;
    }

    private static function unreadConversationCountForReferralUser(User $user): int
    {
        $userId = (string) $user->id;

        $effectiveConversationIdSql = "COALESCE(NULLIF(messages.conversation_id,''), CONCAT('msg-', messages.id))";

        $base = DB::table('messages')
            ->leftJoin('message_conversation_deletions as mcd', function ($join) use ($userId, $effectiveConversationIdSql) {
                $join->on(DB::raw($effectiveConversationIdSql), '=', 'mcd.conversation_id')
                    ->where('mcd.user_id', '=', $userId);
            })
            ->where(function ($q) use ($userId) {
                $q->whereRaw('CAST(messages.recipient_id AS CHAR) = ?', [$userId])
                    ->orWhere(function ($q2) use ($userId) {
                        $q2->whereRaw('CAST(messages.sender_id AS CHAR) = ?', [$userId]);
                    });
            })
            ->where(function ($q) {
                $q->whereNull('mcd.deleted_at')
                    ->orWhereColumn('messages.created_at', '>', 'mcd.deleted_at');
            });

        $ranked = $base->select([
            'messages.id',
            DB::raw($effectiveConversationIdSql . ' as conversation_id'),
            'messages.sender',
            'messages.recipient_id',
            'messages.is_read',
            'messages.created_at',
            DB::raw("ROW_NUMBER() OVER (PARTITION BY {$effectiveConversationIdSql} ORDER BY messages.created_at DESC, messages.id DESC) as rn"),
        ]);

        $latest = DB::query()->fromSub($ranked, 't')->where('t.rn', '=', 1);

        $isUnread = self::unreadFlagPredicateSql('t.is_read');

        $count = $latest
            ->whereRaw('CAST(t.recipient_id AS CHAR) = ?', [$userId])
            ->whereRaw("({$isUnread})")
            ->count();

        return (int) $count;
    }

    private static function unreadConversationCountForStudentOrGuest(User $user): int
    {
        $userId = (int) $user->id;

        $effectiveConversationIdSql = "COALESCE(NULLIF(messages.conversation_id,''), CONCAT('student-', messages.user_id))";

        $base = DB::table('messages')
            ->leftJoin('message_conversation_deletions as mcd', function ($join) use ($userId, $effectiveConversationIdSql) {
                $join->on(DB::raw($effectiveConversationIdSql), '=', 'mcd.conversation_id')
                    ->where('mcd.user_id', '=', $userId);
            })
            ->where('messages.user_id', '=', $userId)
            ->where(function ($q) {
                $q->whereNull('mcd.deleted_at')
                    ->orWhereColumn('messages.created_at', '>', 'mcd.deleted_at');
            });

        $ranked = $base->select([
            'messages.id',
            DB::raw($effectiveConversationIdSql . ' as conversation_id'),
            'messages.sender',
            'messages.is_read',
            'messages.created_at',
            DB::raw("ROW_NUMBER() OVER (PARTITION BY {$effectiveConversationIdSql} ORDER BY messages.created_at DESC, messages.id DESC) as rn"),
        ]);

        $latestPerConversation = DB::query()
            ->fromSub($ranked, 't')
            ->where('t.rn', '=', 1);

        $isUnread = self::unreadFlagPredicateSql('t.is_read');

        $count = $latestPerConversation
            ->whereIn('t.sender', ['counselor', 'system'])
            ->whereRaw("({$isUnread})")
            ->count();

        return (int) $count;
    }
}