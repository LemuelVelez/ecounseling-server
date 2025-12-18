<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    private function conversationIdFor(
        string $senderRole,
        ?int $senderId,
        string $recipientRole,
        ?int $recipientId,
        ?int $studentOwnerId = null
    ): string {
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

        // Counselor-to-counselor direct thread
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

    /**
     * ✅ Normalize avatar_url into a usable absolute URL for the React app.
     * Mirrors the behavior you already rely on in directory endpoints + frontend resolver:
     * - keep data:/blob: as-is
     * - keep absolute http(s) as-is BUT rewrite wrong /api/storage -> /storage
     * - normalize common Laravel storage prefixes
     * - ensure returned value is absolute via url("/storage/..")
     */
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

            // normalize common wrong absolute paths
            $path = preg_replace('#^/api/storage/#i', '/storage/', $path);
            $path = preg_replace('#^/api/public/storage/#i', '/storage/', $path);
            $path = preg_replace('#^/storage/app/public/#i', '/storage/', $path);

            $parts['path'] = $path;

            return $this->unparseUrl($parts);
        }

        // Scheme-relative URL
        if (str_starts_with($s, '//')) {
            return request()->getScheme() . ':' . $s;
        }

        // Strip common Laravel prefixes
        $s = preg_replace('#^storage/app/public/#i', '', $s);
        $s = preg_replace('#^public/#i', '', $s);

        $normalized = ltrim($s, '/');

        $lower = strtolower($normalized);
        $alreadyStorage = str_starts_with($lower, 'storage/')
            || str_starts_with($lower, 'api/storage/');

        if (! $alreadyStorage && $this->looksLikeFilePath($normalized)) {
            $normalized = 'storage/' . $normalized;
        }

        // normalize relative api/storage/* => storage/*
        $normalized = preg_replace('#^api/storage/#i', 'storage', $normalized);

        $finalPath = '/' . ltrim($normalized, '/');

        return url($finalPath);
    }

    /**
     * GET /counselor/messages
     *
     * ✅ FIX:
     * - Include sender/recipient avatars so Messages UI can render peerAvatarUrl.
     * - For student/guest messages that may not have sender_id, fall back to messages.user_id's avatar.
     *
     * Read flag behavior:
     * - For counselor context, we alias counselor_is_read as is_read in the response.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->isCounselor($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Effective conversation id used in joins + response (covers any legacy nulls)
        $effectiveConversationIdSql = "COALESCE(messages.conversation_id, CONCAT('student-', messages.user_id))";

        $messages = Message::query()
            // ✅ Join users for avatar lookup
            ->leftJoin('users as sender_u', 'messages.sender_id', '=', 'sender_u.id')
            ->leftJoin('users as recipient_u', 'messages.recipient_id', '=', 'recipient_u.id')
            ->leftJoin('users as owner_u', 'messages.user_id', '=', 'owner_u.id')

            ->leftJoin('message_conversation_deletions as mcd', function ($join) use ($user, $effectiveConversationIdSql) {
                $join->on(DB::raw($effectiveConversationIdSql), '=', 'mcd.conversation_id')
                    ->where('mcd.user_id', '=', (int) $user->id);
            })
            ->where(function ($q) use ($user) {
                // 1) Counselor inbox (direct or office): includes student/guest -> counselor messages
                $q->where(function ($q2) use ($user) {
                    $q2->where('messages.recipient_role', 'counselor')
                        ->where(function ($q3) use ($user) {
                            $q3->whereNull('messages.recipient_id')
                                ->orWhere('messages.recipient_id', $user->id);
                        });
                })

                // 2) All student threads (full conversation history)
                ->orWhere(function ($q2) {
                    $q2->whereNotNull('messages.conversation_id')
                        ->where('messages.conversation_id', 'like', 'student-%');
                })

                // 3) Also include anything the current counselor sent (compatibility / sent-items)
                ->orWhere(function ($q2) use ($user) {
                    $q2->where('messages.sender', 'counselor')
                        ->where('messages.sender_id', $user->id);
                });
            })
            // ✅ Hide conversations deleted by this counselor (unless message is newer than deletion timestamp)
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
                'messages.sender_name',
                'messages.recipient_id',
                'messages.recipient_role',
                'messages.content',
                'messages.created_at',
                'messages.updated_at',
            ])
            ->selectRaw($effectiveConversationIdSql . ' as conversation_id')
            ->selectRaw('messages.counselor_is_read as is_read')

            // ✅ Avatar fields for frontend (peerAvatarUrl)
            ->selectRaw("COALESCE(sender_u.avatar_url, owner_u.avatar_url) as sender_avatar_url")
            ->selectRaw("recipient_u.avatar_url as recipient_avatar_url")
            ->selectRaw("owner_u.avatar_url as user_avatar_url")
            ->get();

        // ✅ Normalize avatar URLs to usable absolute URLs
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

    /**
     * POST /counselor/messages
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->isCounselor($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'content' => ['required', 'string'],
            // ✅ allow admin
            'recipient_role' => ['nullable', 'in:student,guest,counselor,admin'],
            'recipient_id' => ['nullable', 'integer', 'exists:users,id'],

            // Accept string OR integer conversation ids from the frontend
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

        $recipientRole = $data['recipient_role'] ?? 'counselor';
        $recipientId = isset($data['recipient_id']) ? (int) $data['recipient_id'] : null;

        // Enforce recipient requirements
        if ($recipientRole !== 'counselor' && ! $recipientId) {
            return response()->json([
                'message' => 'recipient_id is required for student/guest/admin recipients.',
            ], 422);
        }

        $recipientUser = null;

        // If sending to a specific user, ensure their role matches expected
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
        }

        // Canonical conversation id
        $canonicalConversationId = $this->conversationIdFor(
            'counselor',
            (int) $user->id,
            $recipientRole,
            $recipientId,
            null
        );

        $conversationHint = $data['conversation_id'] ?? null;
        $conversationId = ((string) $conversationHint === $canonicalConversationId)
            ? $canonicalConversationId
            : $canonicalConversationId;

        $message = new Message();
        $message->sender = 'counselor';
        $message->sender_id = (int) $user->id;
        $message->sender_name = $user->name ?? null;

        $message->recipient_role = $recipientRole;
        $message->recipient_id = $recipientId;
        $message->conversation_id = $conversationId;

        $message->content = $data['content'];

        // Preserve legacy "user_id" meaning:
        // - if sending to student/guest => user_id = recipient
        // - otherwise => set to counselor sender
        $message->user_id = $recipientId && in_array($recipientRole, ['student', 'guest'], true)
            ? $recipientId
            : (int) $user->id;

        // Read flags:
        if (in_array($recipientRole, ['student', 'guest'], true)) {
            $message->is_read = false;
            $message->student_read_at = null;
        } else {
            $message->is_read = true;
            $message->student_read_at = now();
        }

        if ($recipientRole === 'counselor') {
            $message->counselor_is_read = false;
            $message->counselor_read_at = null;
        } else {
            $message->counselor_is_read = true;
            $message->counselor_read_at = now();
        }

        $message->save();

        // ✅ include avatar URLs in response so UI updates immediately
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
            'conversation_id' => $message->conversation_id,
            'content' => $message->content,
            'is_read' => (bool) $message->counselor_is_read,
            'created_at' => $message->created_at,
            'updated_at' => $message->updated_at,

            // ✅ new fields used by frontend avatar picker
            'sender_avatar_url' => $senderAvatar,
            'recipient_avatar_url' => $recipientAvatar,
        ];

        return response()->json([
            'message' => 'Message sent.',
            'messageRecord' => $responseRecord,
        ], 201);
    }

    /**
     * POST /counselor/messages/mark-as-read
     */
    public function markAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

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