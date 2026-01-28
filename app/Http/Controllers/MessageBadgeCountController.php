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

    private static function isReferralUser(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));

        return str_contains($role, 'dean')
            || str_contains($role, 'registrar')
            || str_contains($role, 'program chair')
            || str_contains($role, 'program_chair')
            || str_contains($role, 'programchair')
            || str_contains($role, 'chair');
    }

    /**
     * ✅ Public helper used by NotificationController
     *
     * IMPORTANT:
     * We count "unread conversations" by looking at the LATEST message per conversation:
     * - Counselor: latest message is addressed to counselor AND counselor_is_read=false
     * - Student/Guest: latest message is from counselor/system AND is_read=false
     *
     * This prevents the badge from staying nonzero because of older unread messages
     * that are already "handled" (e.g., you already replied, so latest message is yours).
     */
    public static function unreadConversationCountFor(User $user): int
    {
        if (self::isCounselor($user)) {
            return self::unreadConversationCountForCounselor($user);
        }

        if (self::isReferralUser($user)) {
            // Your current system doesn't implement referral-user message read flags.
            // Keep at 0 unless you add referral-user messaging read tracking.
            return 0;
        }

        // Student / Guest
        return self::unreadConversationCountForStudentOrGuest($user);
    }

    /**
     * Optional endpoint if you want to use it directly later:
     * GET /messages/unread-conversations-count
     */
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

        // Same effective conversation key you used in CounselorMessageController@index
        $effectiveConversationIdSql = "COALESCE(messages.conversation_id, CONCAT('student-', messages.user_id))";

        $base = DB::table('messages')
            ->leftJoin('message_conversation_deletions as mcd', function ($join) use ($userId, $effectiveConversationIdSql) {
                $join->on(DB::raw($effectiveConversationIdSql), '=', 'mcd.conversation_id')
                    ->where('mcd.user_id', '=', $userId);
            })
            ->where(function ($q) use ($userId) {
                // 1) Incoming to counselor (direct or office)
                $q->where(function ($q2) use ($userId) {
                    $q2->where('messages.recipient_role', 'counselor')
                        ->where(function ($q3) use ($userId) {
                            $q3->whereNull('messages.recipient_id')
                                ->orWhere('messages.recipient_id', '=', $userId);
                        });
                })

                // 2) Student threads (conversation_id like student-%)
                ->orWhere(function ($q2) {
                    $q2->whereNotNull('messages.conversation_id')
                        ->where('messages.conversation_id', 'like', 'student-%');
                })

                // 3) Anything the current counselor sent (so latest message can be your reply)
                ->orWhere(function ($q2) use ($userId) {
                    $q2->where('messages.sender', 'counselor')
                        ->where('messages.sender_id', '=', $userId);
                });
            })
            // Respect conversation deletions (hide messages before deleted_at for that user)
            ->where(function ($q) {
                $q->whereNull('mcd.deleted_at')
                    ->orWhereColumn('messages.created_at', '>', 'mcd.deleted_at');
            });

        // Rank messages per conversation (latest = rn=1)
        $ranked = $base->select([
            'messages.id',
            DB::raw($effectiveConversationIdSql . ' as conversation_id'),
            'messages.sender',
            'messages.recipient_role',
            'messages.recipient_id',
            'messages.counselor_is_read',
            'messages.created_at',
            DB::raw("ROW_NUMBER() OVER (PARTITION BY {$effectiveConversationIdSql} ORDER BY messages.created_at DESC, messages.id DESC) as rn"),
        ]);

        $latestPerConversation = DB::query()
            ->fromSub($ranked, 't')
            ->where('t.rn', '=', 1);

        // Count conversations where the LATEST message is an unread incoming message to counselor
        $count = $latestPerConversation
            ->where('t.recipient_role', '=', 'counselor')
            ->where(function ($q) use ($userId) {
                $q->whereNull('t.recipient_id')
                    ->orWhere('t.recipient_id', '=', $userId);
            })
            ->where('t.counselor_is_read', '=', false)
            ->count();

        return (int) $count;
    }

    private static function unreadConversationCountForStudentOrGuest(User $user): int
    {
        $userId = (int) $user->id;

        // Student/guest thread key fallback
        $effectiveConversationIdSql = "COALESCE(messages.conversation_id, CONCAT('student-', messages.user_id))";

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

        // Student/Guest unread: latest message is from counselor/system AND student read flag is false
        $count = $latestPerConversation
            ->whereIn('t.sender', ['counselor', 'system'])
            ->where('t.is_read', '=', false)
            ->count();

        return (int) $count;
    }
}