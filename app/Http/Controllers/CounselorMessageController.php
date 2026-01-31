<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CounselorMessageController extends Controller
{
    private function isCounselor(?User $user): bool
    {
        if (! $user) return false;
        $role = strtolower((string) ($user->role ?? ''));

        // ✅ also allow "guidance" role variants
        return str_contains($role, 'counselor')
            || str_contains($role, 'counsellor')
            || str_contains($role, 'guidance');
    }

    private function isReferralUserRoleString(string $role): bool
    {
        $r = strtolower(trim($role));
        if ($r === '') return false;

        return $r === 'referral_user'
            || $r === 'referral-user'
            || $r === 'referral user'
            || str_contains($r, 'referral_user')
            || str_contains($r, 'referral-user')
            || str_contains($r, 'dean')
            || str_contains($r, 'registrar')
            || str_contains($r, 'program_chair')
            || str_contains($r, 'program chair')
            || str_contains($r, 'programchair');
    }

    private function isReferralUser(?User $user): bool
    {
        if (! $user) return false;
        return $this->isReferralUserRoleString((string) ($user->role ?? ''));
    }

    /**
     * ✅ Resolve authenticated user across possible guards (web/session OR api/sanctum token).
     * This prevents 401 caused by "wrong guard" when the SPA uses Authorization: Bearer.
     */
    private function resolveUser(Request $request): ?User
    {
        $u = $request->user();
        if ($u instanceof User) return $u;

        try {
            $u = $request->user('web');
            if ($u instanceof User) return $u;
        } catch (\Throwable $e) {
        }

        try {
            $u = $request->user('api');
            if ($u instanceof User) return $u;
        } catch (\Throwable $e) {
        }

        try {
            $u = $request->user('sanctum');
            if ($u instanceof User) return $u;
        } catch (\Throwable $e) {
        }

        try {
            $u = Auth::guard('web')->user();
            if ($u instanceof User) return $u;
        } catch (\Throwable $e) {
        }

        try {
            $u = Auth::guard('api')->user();
            if ($u instanceof User) return $u;
        } catch (\Throwable $e) {
        }

        try {
            $u = Auth::guard('sanctum')->user();
            if ($u instanceof User) return $u;
        } catch (\Throwable $e) {
        }

        return null;
    }

    private function conversationIdFor(
        string $senderRole,
        ?int $senderId,
        string $recipientRole,
        ?int $recipientId,
        ?int $studentOwnerId = null
    ): string {
        $recipientRole = strtolower(trim($recipientRole));

        // Student/guest thread: keep stable thread id per student/guest owner
        if (($recipientRole === 'student' || $recipientRole === 'guest') && $recipientId) {
            return "student-{$recipientId}";
        }

        if (($senderRole === 'student' || $senderRole === 'guest') && $studentOwnerId) {
            return "student-{$studentOwnerId}";
        }

        // ✅ Admin direct thread
        if ($recipientRole === 'admin' && $recipientId) {
            return "admin-{$recipientId}";
        }

        // ✅ Referral-user direct thread (Dean/Registrar/Program Chair)
        if ($recipientRole === 'referral_user' && $recipientId) {
            return "referral_user-{$recipientId}";
        }

        // Counselor-to-counselor direct thread (stable symmetric key)
        if ($recipientRole === 'counselor' && $senderId && $recipientId) {
            $a = min($senderId, $recipientId);
            $b = max($senderId, $recipientId);
            return "counselor-{$a}-{$b}";
        }

        // Counselor office thread (broadcast/group)
        if ($recipientRole === 'counselor' && ! $recipientId) {
            return "counselor-office";
        }

        return "general";
    }

    private function looksLikeFilePath(string $s): bool
    {
        return (bool) (
            preg_match('/\.[a-z0-9]{2,5}(\?.*)?$/i', $s) ||
            preg_match('#(^|/)(avatars|avatar|profile|profiles|images|uploads)(/|$)#i', $s)
        );
    }

    private function unparseUrl(array $parts): string
    {
        $scheme   = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $user     = $parts['user'] ?? '';
        $pass     = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth     = $user !== '' ? $user . $pass . '@' : '';
        $host     = $parts['host'] ?? '';
        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path     = $parts['path'] ?? '';
        $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $auth . $host . $port . $path . $query . $fragment;
    }

    private function resolveAvatarUrl(?string $raw): ?string
    {
        if ($raw == null) return null;

        $s = trim((string) $raw);
        if ($s === '') return null;

        $s = str_replace('\\', '/', $s);

        if (preg_match('#^(data:|blob:)#i', $s)) return $s;

        // Absolute URL
        if (preg_match('#^https?://#i', $s)) {
            $parts = parse_url($s);
            if (! is_array($parts)) return $s;

            $path = isset($parts['path']) ? str_replace('\\', '/', (string) $parts['path']) : '';

            $path = preg_replace('#^/api/storage/#i', '/storage/', $path);
            $path = preg_replace('#^/api/public/storage/#i', '/storage/', $path);
            $path = preg_replace('#^/storage/app/public/#i', '/storage/', $path);

            $parts['path'] = $path;

            return $this->unparseUrl($parts);
        }

        if (str_starts_with($s, '//')) {
            return request()->getScheme() . ':' . $s;
        }

        $s = preg_replace('#^storage/app/public/#i', '', $s);
        $s = preg_replace('#^public/#i', '', $s);

        $normalized = ltrim($s, '/');

        $lower = strtolower($normalized);
        $alreadyStorage = str_starts_with($lower, 'storage/')
            || str_starts_with($lower, 'api/storage/');

        if (! $alreadyStorage && $this->looksLikeFilePath($normalized)) {
            $normalized = 'storage/' . $normalized;
        }

        $normalized = preg_replace('#^api/storage/#i', 'storage', $normalized);

        $finalPath = '/' . ltrim($normalized, '/');

        return url($finalPath);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->isCounselor($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        /**
         * ✅ FIX (duplicate threads):
         * Treat legacy/unstable conversation_id values (e.g., numeric ids, random ids, "general")
         * as NOT canonical. Always return a canonical id based on role + participant ids:
         * - student-{studentId}
         * - admin-{adminId}
         * - referral_user-{id}
         * - counselor-{minId}-{maxId}
         * - counselor-office
         */
        $effectiveConversationIdSql = "
            CASE
                WHEN NULLIF(COALESCE(messages.conversation_id, ''), '') IS NOT NULL
                    AND LOWER(COALESCE(messages.conversation_id, '')) <> 'general'
                    AND (
                        LOWER(COALESCE(messages.conversation_id, '')) LIKE 'student-%'
                        OR LOWER(COALESCE(messages.conversation_id, '')) LIKE 'admin-%'
                        OR LOWER(COALESCE(messages.conversation_id, '')) LIKE 'referral_user-%'
                        OR LOWER(COALESCE(messages.conversation_id, '')) LIKE 'counselor-%'
                        OR LOWER(COALESCE(messages.conversation_id, '')) = 'counselor-office'
                    )
                    THEN messages.conversation_id

                WHEN LOWER(COALESCE(messages.recipient_role, '')) IN ('student','guest')
                    AND messages.recipient_id IS NOT NULL
                    THEN CONCAT('student-', messages.recipient_id)

                WHEN LOWER(COALESCE(messages.recipient_role, '')) = 'admin'
                    AND messages.recipient_id IS NOT NULL
                    THEN CONCAT('admin-', messages.recipient_id)

                WHEN LOWER(COALESCE(messages.recipient_role, '')) IN ('referral_user','dean','registrar','program_chair')
                    AND messages.recipient_id IS NOT NULL
                    THEN CONCAT('referral_user-', messages.recipient_id)

                WHEN LOWER(COALESCE(messages.recipient_role, '')) = 'counselor'
                    AND messages.recipient_id IS NOT NULL
                    AND messages.sender_id IS NOT NULL
                    THEN CONCAT('counselor-', LEAST(messages.sender_id, messages.recipient_id), '-', GREATEST(messages.sender_id, messages.recipient_id))

                WHEN LOWER(COALESCE(messages.recipient_role, '')) = 'counselor'
                    AND messages.recipient_id IS NULL
                    THEN 'counselor-office'

                WHEN LOWER(COALESCE(messages.sender, '')) IN ('referral_user','dean','registrar','program_chair')
                    AND messages.sender_id IS NOT NULL
                    THEN CONCAT('referral_user-', messages.sender_id)

                WHEN LOWER(COALESCE(messages.sender, '')) = 'admin'
                    AND messages.sender_id IS NOT NULL
                    THEN CONCAT('admin-', messages.sender_id)

                WHEN LOWER(COALESCE(messages.sender, '')) IN ('student','guest')
                    AND messages.user_id IS NOT NULL
                    THEN CONCAT('student-', messages.user_id)

                ELSE CONCAT('student-', COALESCE(messages.user_id, 0))
            END
        ";

        $resolvedSenderNameSql = "
            CASE
                WHEN LOWER(COALESCE(messages.sender, '')) = 'system'
                    THEN COALESCE(NULLIF(messages.sender_name, ''), 'Guidance & Counseling Office')
                ELSE
                    COALESCE(
                        NULLIF(messages.sender_name, ''),
                        NULLIF(sender_u.name, ''),
                        NULLIF(owner_u.name, '')
                    )
            END
        ";

        $resolvedRecipientNameSql = "
            CASE
                WHEN LOWER(COALESCE(messages.recipient_role, '')) IN ('student','guest','admin','referral_user','dean','registrar','program_chair')
                    THEN COALESCE(NULLIF(recipient_u.name, ''), NULLIF(owner_u.name, ''))
                ELSE NULLIF(recipient_u.name, '')
            END
        ";

        $messages = Message::query()
            ->leftJoin('users as sender_u', 'messages.sender_id', '=', 'sender_u.id')
            ->leftJoin('users as recipient_u', 'messages.recipient_id', '=', 'recipient_u.id')
            ->leftJoin('users as owner_u', 'messages.user_id', '=', 'owner_u.id')

            ->leftJoin('message_conversation_deletions as mcd', function ($join) use ($user, $effectiveConversationIdSql) {
                $join->on(DB::raw($effectiveConversationIdSql), '=', 'mcd.conversation_id')
                    ->where('mcd.user_id', '=', (int) $user->id);
            })
            ->where(function ($q) use ($user) {
                $q->where(function ($q2) use ($user) {
                    $q2->where('messages.recipient_role', 'counselor')
                        ->where(function ($q3) use ($user) {
                            $q3->whereNull('messages.recipient_id')
                                ->orWhere('messages.recipient_id', $user->id);
                        });
                })
                ->orWhere(function ($q2) {
                    $q2->whereNotNull('messages.conversation_id')
                        ->where('messages.conversation_id', 'like', 'student-%');
                })
                ->orWhere(function ($q2) use ($user) {
                    $q2->where('messages.sender', 'counselor')
                        ->where('messages.sender_id', $user->id);
                });
            })
            ->where(function ($q) {
                $q->whereNull('mcd.deleted_at')
                    ->orWhereColumn('messages.created_at', '>', 'mcd.deleted_at');
            })
            ->orderBy('messages.created_at', 'asc')
            ->orderBy('messages.id', 'asc')
            ->select([
                'messages.id',
                'messages.user_id',
                'messages.sender',
                'messages.sender_id',
                'messages.recipient_id',
                'messages.recipient_role',
                'messages.content',
                'messages.created_at',
                'messages.updated_at',
            ])
            ->selectRaw($effectiveConversationIdSql . ' as conversation_id')
            ->selectRaw('messages.counselor_is_read as is_read')
            ->selectRaw($resolvedSenderNameSql . ' as sender_name')
            ->selectRaw($resolvedRecipientNameSql . ' as recipient_name')
            ->selectRaw("NULLIF(owner_u.name, '') as user_name")
            ->selectRaw("COALESCE(sender_u.avatar_url, owner_u.avatar_url) as sender_avatar_url")
            ->selectRaw("recipient_u.avatar_url as recipient_avatar_url")
            ->selectRaw("owner_u.avatar_url as user_avatar_url")
            ->get();

        $messages->transform(function ($m) {
            $m->sender_avatar_url = $this->resolveAvatarUrl($m->sender_avatar_url ?? null);
            $m->recipient_avatar_url = $this->resolveAvatarUrl($m->recipient_avatar_url ?? null);
            $m->user_avatar_url = $this->resolveAvatarUrl($m->user_avatar_url ?? null);
            return $m;
        });

        return response()->json([
            'message'  => 'Fetched counselor messages.',
            'messages' => $messages,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->isCounselor($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'content' => ['required', 'string'],

            // ✅ allow referral_user (and legacy office role names)
            'recipient_role' => ['nullable', 'in:student,guest,counselor,admin,referral_user,referral-user,referral user,dean,registrar,program_chair'],

            'recipient_id' => ['nullable', 'integer', 'exists:users,id'],
            'conversation_id' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    if ($value === null) return;
                    if (! is_string($value) && ! is_int($value)) {
                        $fail('conversation_id must be a string or integer.');
                        return;
                    }
                    if (strlen((string) $value) > 120) {
                        $fail('conversation_id may not be greater than 120 characters.');
                    }
                },
            ],
        ]);

        $recipientRoleRaw = strtolower(trim((string) ($data['recipient_role'] ?? 'counselor')));
        $recipientId = isset($data['recipient_id']) ? (int) $data['recipient_id'] : null;

        // Normalize any office variants to canonical "referral_user"
        if (in_array($recipientRoleRaw, ['referral-user', 'referral user', 'dean', 'registrar', 'program_chair', 'program chair', 'programchair'], true)) {
            $recipientRoleRaw = 'referral_user';
        }

        $recipientRole = $recipientRoleRaw ?: 'counselor';

        if ($recipientRole !== 'counselor' && ! $recipientId) {
            return response()->json([
                'message' => 'recipient_id is required for student/guest/admin/referral_user recipients.',
            ], 422);
        }

        $recipientUser = null;

        if ($recipientId) {
            $recipientUser = User::find($recipientId);
            if (! $recipientUser) {
                return response()->json(['message' => 'Recipient not found.'], 404);
            }

            $targetRole = strtolower((string) ($recipientUser->role ?? ''));

            if ($recipientRole === 'student' && ! str_contains($targetRole, 'student')) {
                return response()->json(['message' => 'Recipient is not a student.'], 422);
            }

            if ($recipientRole === 'guest' && ! str_contains($targetRole, 'guest')) {
                return response()->json(['message' => 'Recipient is not a guest.'], 422);
            }

            if ($recipientRole === 'counselor'
                && ! (str_contains($targetRole, 'counselor') || str_contains($targetRole, 'counsellor') || str_contains($targetRole, 'guidance'))
            ) {
                return response()->json(['message' => 'Recipient is not a counselor.'], 422);
            }

            if ($recipientRole === 'admin' && ! str_contains($targetRole, 'admin')) {
                return response()->json(['message' => 'Recipient is not an admin.'], 422);
            }

            if ($recipientRole === 'referral_user' && ! $this->isReferralUser($recipientUser)) {
                return response()->json(['message' => 'Recipient is not a referral user.'], 422);
            }
        }

        $canonicalConversationId = $this->conversationIdFor(
            'counselor',
            (int) $user->id,
            $recipientRole,
            $recipientId,
            null
        );

        $message = new Message();
        $message->sender = 'counselor';
        $message->sender_id = (int) $user->id;
        $message->sender_name = $user->name ?? null;

        $message->recipient_role = $recipientRole;
        $message->recipient_id = $recipientId;
        $message->conversation_id = $canonicalConversationId;

        $message->content = $data['content'];

        // Keep legacy behavior for student/guest owner; otherwise owner is the counselor (sender).
        $message->user_id = $recipientId && in_array($recipientRole, ['student', 'guest'], true)
            ? $recipientId
            : (int) $user->id;

        // is_read = "read by NON-counselor recipient"
        if ($recipientRole === 'counselor') {
            $message->is_read = true;
            $message->student_read_at = now();
        } else {
            $message->is_read = false;
            $message->student_read_at = null;
        }

        // counselor read state
        if ($recipientRole === 'counselor') {
            $message->counselor_is_read = false;
            $message->counselor_read_at = null;
        } else {
            $message->counselor_is_read = true;
            $message->counselor_read_at = now();
        }

        $message->save();

        $senderAvatar = $this->resolveAvatarUrl($user->avatar_url ?? null);
        $recipientAvatar = $this->resolveAvatarUrl($recipientUser?->avatar_url ?? null);

        $responseRecord = [
            'id' => $message->id,
            'user_id' => $message->user_id,
            'sender' => $message->sender,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender_name,
            'recipient_id' => $message->recipient_id,
            'recipient_role' => $message->recipient_role,
            'recipient_name' => $recipientUser?->name ?? null,
            'conversation_id' => $message->conversation_id,
            'content' => $message->content,
            'is_read' => (bool) $message->counselor_is_read,
            'created_at' => $message->created_at,
            'updated_at' => $message->updated_at,
            'sender_avatar_url' => $senderAvatar,
            'recipient_avatar_url' => $recipientAvatar,
        ];

        return response()->json([
            'message' => 'Message sent.',
            'messageRecord' => $responseRecord,
        ], 201);
    }

    public function markAsRead(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->isCounselor($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'message_ids' => ['nullable', 'array'],
            'message_ids.*' => ['integer'],
        ]);

        $query = Message::query()
            ->where('recipient_role', 'counselor')
            ->where('counselor_is_read', false)
            ->where(function ($q) use ($user) {
                $q->whereNull('recipient_id')
                    ->orWhere('recipient_id', $user->id);
            });

        $messageIds = $data['message_ids'] ?? null;
        if (is_array($messageIds) && count($messageIds) > 0) {
            $query->whereIn('id', $messageIds);
        }

        $updatedCount = $query->update([
            'counselor_is_read' => true,
            'counselor_read_at' => now(),
        ]);

        return response()->json([
            'message' => 'Messages marked as read.',
            'updated_count' => $updatedCount,
        ]);
    }
}