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
        return str_contains($role, 'counselor') || str_contains($role, 'counsellor');
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

    /**
     * GET /counselor/messages
     *
     * IMPORTANT FIX:
     * - Exclude conversations deleted by THIS counselor (persistently) using message_conversation_deletions.
     * - If new messages arrive after deletion, they will show again (only newer than deleted_at).
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

                // 2) All student threads (full conversation history, regardless of which counselor sent replies)
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
            ->get();

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
            'recipient_role' => ['nullable', 'in:student,guest,counselor'],
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
                'message' => 'recipient_id is required for student/guest recipients.',
            ], 422);
        }

        // If sending to a specific user, ensure their role matches expected (student/guest/counselor)
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

            if ($recipientRole === 'counselor' && ! (str_contains($targetRole, 'counselor') || str_contains($targetRole, 'counsellor'))) {
                return response()->json(['message' => 'Recipient is not a counselor.'], 422);
            }
        }

        // Canonical conversation id (keeps student threads stable and counselor threads deterministic)
        $canonicalConversationId = $this->conversationIdFor(
            'counselor',
            (int) $user->id,
            $recipientRole,
            $recipientId,
            null
        );

        // Use the canonical id; only accept the provided hint if it exactly matches.
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
        // - if sending to student/guest => user_id = recipient (so /student/messages can query by user_id)
        // - otherwise => set to counselor sender (keeps non-null constraints)
        $message->user_id = $recipientId && in_array($recipientRole, ['student', 'guest'], true)
            ? $recipientId
            : (int) $user->id;

        // Read flags:
        // Student read flag (is_read) only matters for student/guest recipients
        if (in_array($recipientRole, ['student', 'guest'], true)) {
            $message->is_read = false;
            $message->student_read_at = null;
        } else {
            $message->is_read = true;
            $message->student_read_at = now();
        }

        // Counselor read flag matters when counselor is recipient
        if ($recipientRole === 'counselor') {
            $message->counselor_is_read = false;
            $message->counselor_read_at = null;
        } else {
            $message->counselor_is_read = true;
            $message->counselor_read_at = now();
        }

        $message->save();

        // IMPORTANT: return counselor_is_read as is_read for counselor context
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