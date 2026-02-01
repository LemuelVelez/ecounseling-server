<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // ✅ IMPORTANT: include literal referral_user and common office roles
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

    /**
     * ✅ Public helper used by NotificationController
     *
     * Counts "unread conversations" by looking at the LATEST message per conversation:
     * - Counselor: latest message is addressed to counselor AND (counselor_is_read=false OR is_read=false)
     * - Admin: latest message is addressed to admin AND is_read=false
     * - Referral user: latest message is addressed to referral user AND is_read=false
     * - Student/Guest: latest message is from counselor/system AND is_read=false
     */
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

        // ✅ IMPORTANT: support both schemas:
        // - counselor_is_read column
        // - OR is_read column (older implementations)
        $count = $latestPerConversation
            ->where('t.recipient_role', '=', 'counselor')
            ->where(function ($q) use ($userId) {
                $q->whereNull('t.recipient_id')
                    ->orWhere('t.recipient_id', '=', $userId);
            })
            ->where(function ($q) {
                $q->where('t.counselor_is_read', '=', false)
                  ->orWhereNull('t.counselor_is_read')
                  ->where(function ($q2) {
                      $q2->where('t.is_read', '=', false)
                         ->orWhereNull('t.is_read')
                         ->orWhere('t.is_read', '=', 0);
                  });
            })
            ->count();

        return (int) $count;
    }

    private static function unreadConversationCountForAdmin(User $user): int
    {
        $userId = (string) $user->id;

        // Group by conversation_id when present (admin controller uses canonical ids there)
        $effectiveConversationIdSql = "COALESCE(NULLIF(messages.conversation_id,''), CONCAT('msg-', messages.id))";

        $base = DB::table('messages')
            ->leftJoin('message_conversation_deletions as mcd', function ($join) use ($userId, $effectiveConversationIdSql) {
                $join->on(DB::raw($effectiveConversationIdSql), '=', 'mcd.conversation_id')
                    ->where('mcd.user_id', '=', $userId);
            })
            ->where(function ($q) use ($userId) {
                // messages involving this admin (so latest message can be admin reply)
                $q->whereRaw('CAST(messages.recipient_id AS CHAR) = ?', [$userId])
                  ->orWhere(function ($q2) use ($userId) {
                      $q2->where('messages.sender', '=', 'admin')
                         ->whereRaw('CAST(messages.sender_id AS CHAR) = ?', [$userId]);
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
            'messages.recipient_role',
            'messages.is_read',
            'messages.created_at',
            DB::raw("ROW_NUMBER() OVER (PARTITION BY {$effectiveConversationIdSql} ORDER BY messages.created_at DESC, messages.id DESC) as rn"),
        ]);

        $latest = DB::query()->fromSub($ranked, 't')->where('t.rn', '=', 1);

        // Latest must be incoming to this admin AND unread
        $count = $latest
            ->whereRaw('CAST(t.recipient_id AS CHAR) = ?', [$userId])
            ->where(function ($q) {
                $q->where('t.is_read', '=', false)
                  ->orWhereNull('t.is_read')
                  ->orWhere('t.is_read', '=', 0);
            })
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

        $count = $latest
            ->whereRaw('CAST(t.recipient_id AS CHAR) = ?', [$userId])
            ->where(function ($q) {
                $q->where('t.is_read', '=', false)
                  ->orWhereNull('t.is_read')
                  ->orWhere('t.is_read', '=', 0);
            })
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

        $count = $latestPerConversation
            ->whereIn('t.sender', ['counselor', 'system'])
            ->where(function ($q) {
                $q->where('t.is_read', '=', false)
                  ->orWhereNull('t.is_read')
                  ->orWhere('t.is_read', '=', 0);
            })
            ->count();

        return (int) $count;
    }
}