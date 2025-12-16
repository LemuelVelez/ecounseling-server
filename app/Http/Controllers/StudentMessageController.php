<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentMessageController extends Controller
{
    /**
     * GET /student/messages
     *
     * Fetch all messages for the authenticated student/guest.
     *
     * IMPORTANT:
     * Student UI expects "is_read" to be the student read flag.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $messages = Message::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'message'  => 'Fetched your messages.',
            'messages' => $messages,
        ]);
    }

    /**
     * POST /student/messages
     *
     * Create a new message authored by the current student OR guest.
     *
     * This is always routed to the counselor office:
     *   recipient_role = counselor
     *   recipient_id   = null (office inbox)
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
        ]);

        $role = strtolower((string) ($user->role ?? 'student'));
        $senderRole = str_contains($role, 'guest') ? 'guest' : 'student';

        $message = new Message();
        $message->user_id     = $user->id;

        $message->sender      = $senderRole;
        $message->sender_id   = (int) $user->id;
        $message->sender_name = $user->name ?? null;

        $message->recipient_role = 'counselor';
        $message->recipient_id   = null;

        // Stable thread id per student/guest
        $message->conversation_id = "student-{$user->id}";

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