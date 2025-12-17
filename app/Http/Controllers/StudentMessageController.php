<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use App\Models\MessageConversationDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StudentMessageController extends Controller
{
    private function isCounselor(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));
        return str_contains($role, 'counselor') || str_contains($role, 'counsellor');
    }

    /**
     * Build the same "conversation id" key the Student UI uses.
     *
     * Frontend logic:
     * - Prefer message.conversation_id if present
     * - else derive counselor-{id} from sender/recipient
     * - else counselor-office
     */
    private function conversationKey(Message $message): string
    {
        $raw = $message->conversation_id ?? null;
        if ($raw !== null && trim((string) $raw) !== '') {
            return (string) $raw;
        }

        $sender = strtolower((string) ($message->sender ?? ''));
        $senderId = $message->sender_id ?? null;

        $recipientRole = strtolower((string) ($message->recipient_role ?? ''));
        $recipientId = $message->recipient_id ?? null;

        $counselorId = null;

        if ($sender === 'counselor' && $senderId !== null && trim((string) $senderId) !== '') {
            $counselorId = $senderId;
        } elseif ($recipientRole === 'counselor' && $recipientId !== null && trim((string) $recipientId) !== '') {
            $counselorId = $recipientId;
        }

        if ($counselorId !== null && trim((string) $counselorId) !== '') {
            return 'counselor-' . (string) $counselorId;
        }

        return 'counselor-office';
    }

    /**
     * GET /student/messages
     *
     * Fetch all messages for the authenticated student/guest.
     *
     * ✅ FIXED:
     * Respect "conversation deletions" so deleted threads stay hidden after refresh.
     * If a conversation was deleted at time T, only show messages created AFTER T.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Load deletion cutoffs for this user (conversation_id => deleted_at)
        $deletions = MessageConversationDeletion::query()
            ->where('user_id', (int) $user->id)
            ->whereNotNull('deleted_at')
            ->get(['conversation_id', 'deleted_at']);

        $deletedAtByConversation = [];
        foreach ($deletions as $d) {
            $key = trim((string) ($d->conversation_id ?? ''));
            if ($key === '') continue;
            $deletedAtByConversation[$key] = $d->deleted_at; // Carbon (cast)
        }

        // Fetch all messages for this user
        $messages = Message::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Filter out messages that are "before or at" the delete cutoff for that conversation
        $visibleMessages = $messages->filter(function (Message $m) use ($deletedAtByConversation) {
            $key = $this->conversationKey($m);

            if (! array_key_exists($key, $deletedAtByConversation)) {
                return true;
            }

            $cutoff = $deletedAtByConversation[$key] ?? null;
            if (! $cutoff) return true;

            $createdAt = $m->created_at instanceof Carbon
                ? $m->created_at
                : Carbon::parse((string) $m->created_at);

            // Only show messages strictly after the deletion moment
            return $createdAt->gt($cutoff);
        })->values();

        return response()->json([
            'message'  => 'Fetched your messages.',
            'messages' => $visibleMessages,
        ]);
    }

    /**
     * POST /student/messages
     *
     * Create a new message authored by the current student OR guest.
     *
     * Supports optional UI fields:
     *   - recipient_role (must be counselor if provided)
     *   - recipient_id (optional; if provided must be a counselor user)
     *   - conversation_id (hint; will be normalized to the canonical student thread)
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $data = $request->validate([
            'content' => ['required', 'string'],

            // UI may send these; we keep them optional but safe.
            'recipient_role' => ['nullable', 'in:counselor'],
            'recipient_id'   => ['nullable', 'integer', 'exists:users,id'],

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

        $role = strtolower((string) ($user->role ?? 'student'));
        $senderRole = str_contains($role, 'guest') ? 'guest' : 'student';

        $recipientId = isset($data['recipient_id']) ? (int) $data['recipient_id'] : null;

        // If a specific recipient counselor is provided, verify they are actually a counselor.
        if ($recipientId) {
            $recipientUser = User::find($recipientId);
            if (! $recipientUser || ! $this->isCounselor($recipientUser)) {
                return response()->json([
                    'message' => 'Recipient is not a counselor.',
                ], 422);
            }
        }

        // Canonical conversation id for the student/guest thread
        $canonicalConversationId = "student-{$user->id}";

        // Accept the provided conversation_id only if it matches the canonical thread id.
        // (Prevents clients from spoofing/mixing threads.)
        $conversationHint = $data['conversation_id'] ?? null;
        $conversationId = ((string) $conversationHint === $canonicalConversationId)
            ? $canonicalConversationId
            : $canonicalConversationId;

        $message = new Message();
        $message->user_id     = $user->id;

        $message->sender      = $senderRole;
        $message->sender_id   = (int) $user->id;
        $message->sender_name = $user->name ?? null;

        $message->recipient_role = 'counselor';
        $message->recipient_id   = $recipientId; // null = office inbox; int = direct counselor

        $message->conversation_id = $conversationId;

        $message->content = $data['content'];

        // Student has already "read" their own outgoing message
        $message->is_read = true;
        $message->student_read_at = now();

        // Counselor has not read it yet
        $message->counselor_is_read = false;
        $message->counselor_read_at = null;

        $message->save();

        return response()->json([
            'message'       => 'Your message has been sent.',
            'messageRecord' => $message,
        ], 201);
    }

    /**
     * POST /student/messages/mark-as-read
     *
     * Marks messages as read for the student/guest.
     * Only affects messages belonging to current user.
     *
     * - If message_ids omitted/empty => mark all as read.
     */
    public function markAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $data = $request->validate([
            'message_ids'   => ['nullable', 'array'],
            'message_ids.*' => ['integer'],
        ]);

        $query = Message::query()
            ->where('user_id', $user->id)
            ->where('is_read', false);

        // Typically only counselor/system messages are unread for student
        // (Student outgoing messages are stored as is_read=true.)
        $query->whereIn('sender', ['counselor', 'system']);

        $messageIds = $data['message_ids'] ?? null;

        if (is_array($messageIds) && count($messageIds) > 0) {
            $query->whereIn('id', $messageIds);
        }

        $updatedCount = $query->update([
            'is_read' => true,
            'student_read_at' => now(),
        ]);

        return response()->json([
            'message'       => 'Messages marked as read.',
            'updated_count' => $updatedCount,
        ]);
    }
}