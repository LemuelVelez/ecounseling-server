<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Returns messages relevant to counselors.
     *
     * IMPORTANT CHANGE:
     * - Include ALL messages in student threads (conversation_id LIKE 'student-%'),
     *   so counselors can see complete conversations even if another counselor replied.
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

        $messages = Message::query()
            ->where(function ($q) use ($user) {
                // 1) Counselor inbox (direct or office): includes student/guest -> counselor messages
                $q->where(function ($q2) use ($user) {
                    $q2->where('recipient_role', 'counselor')
                        ->where(function ($q3) use ($user) {
                            $q3->whereNull('recipient_id')
                                ->orWhere('recipient_id', $user->id);
                        });
                })

                // 2) All student threads (full conversation history, regardless of which counselor sent replies)
                ->orWhere(function ($q2) {
                    $q2->whereNotNull('conversation_id')
                        ->where('conversation_id', 'like', 'student-%');
                })

                // 3) Also include anything the current counselor sent (compatibility / sent-items)
                ->orWhere(function ($q2) use ($user) {
                    $q2->where('sender', 'counselor')
                        ->where('sender_id', $user->id);
                });
            })
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->select([
                'id',
                'user_id',
                'sender',
                'sender_id',
                'sender_name',
                'recipient_id',
                'recipient_role',
                'conversation_id',
                'content',
                'created_at',
                'updated_at',
            ])
            ->selectRaw('counselor_is_read as is_read')
            ->get();

        return response()->json([
            'message'  => 'Fetched counselor messages.',
            'messages' => $messages,
        ]);
    }

    /**
     * POST /counselor/messages
     *
     * Body:
     *   { content: string, recipient_role?: student|guest|counselor, recipient_id?: int, conversation_id?: string|int }
     *
     * Rules:
     * - counselor may send to student, guest, or counselor
     * - if recipient_role is student/guest (direct), recipient_id is required
     * - if recipient_role is counselor and recipient_id is null => counselor-office broadcast (allowed)
     *
     * NOTE:
     * - conversation_id from the client is treated as a hint; we normalize to a canonical id
     *   for the given sender/recipient pair to keep threads consistent.
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
     *
     * Body:
     *   { message_ids?: int[] }
     *
     * Marks counselor_is_read = true for messages received by counselor inbox:
     * - recipient_role=counselor and (recipient_id is null OR recipient_id = current counselor)
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