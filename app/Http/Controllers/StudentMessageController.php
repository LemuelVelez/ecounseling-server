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
     * Fetch all messages for the authenticated student.
     *
     * Returns a JSON shape matching:
     *   GetStudentMessagesResponseDto (src/api/messages/route.ts)
     *   {
     *     "message"?: string,
     *     "messages": MessageDto[]
     *   }
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
     * Create a new message authored by the current student.
     *
     * Request body (CreateStudentMessagePayload):
     *   { "content": string }
     *
     * Response (CreateStudentMessageResponseDto):
     *   {
     *     "message"?: string,
     *     "messageRecord": MessageDto
     *   }
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

        $message = new Message();
        $message->user_id     = $user->id;
        $message->sender      = 'student';
        $message->sender_name = $user->name ?? null;
        $message->content     = $data['content'];
        // From the student's perspective, their own message is already "read"
        $message->is_read     = true;

        $message->save();

        return response()->json([
            'message'       => 'Your message has been sent.',
            'messageRecord' => $message,
        ], 201);
    }

    /**
     * POST /student/messages/mark-as-read
     *
     * Mark one or more messages as read for the current student.
     *
     * Request body (MarkMessagesReadPayload):
     *   {
     *     "message_ids"?: number[]
     *   }
     *
     * - If message_ids is omitted or an empty array, ALL messages for the student are marked read.
     * - Only messages belonging to the current student are affected.
     *
     * Response (MarkMessagesReadResponseDto):
     *   {
     *     "message"?: string,
     *     "updated_count"?: number
     *   }
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

        $messageIds = $data['message_ids'] ?? null;

        if (is_array($messageIds) && count($messageIds) > 0) {
            $query->whereIn('id', $messageIds);
        }

        $updatedCount = $query->update([
            'is_read' => true,
        ]);

        return response()->json([
            'message'       => 'Messages marked as read.',
            'updated_count' => $updatedCount,
        ]);
    }
}