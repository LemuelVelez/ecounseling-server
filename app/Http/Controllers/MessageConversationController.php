<?php

namespace App\Http\Controllers;

use App\Models\MessageConversationDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageConversationController extends Controller
{
    /**
     * DELETE /messages/conversations/{conversationId}
     * DELETE /messages/thread/{conversationId}
     * DELETE /conversations/{conversationId}
     *
     * "Deletes" (hides) a conversation for the current user only.
     * It does NOT remove messages from the database.
     *
     * Behavior:
     * - After deletion, the conversation disappears on refresh for that user.
     * - If a new message arrives later in the same conversation, it will re-appear (only showing newer messages).
     */
    public function destroy(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $conversationId = trim((string) $conversationId);

        if ($conversationId === '') {
            return response()->json(['message' => 'conversationId is required.'], 422);
        }

        if (strlen($conversationId) > 120) {
            return response()->json(['message' => 'conversationId may not be greater than 120 characters.'], 422);
        }

        $record = MessageConversationDeletion::firstOrNew([
            'user_id' => (int) $user->id,
            'conversation_id' => $conversationId,
        ]);

        $record->deleted_at = now();
        $record->save();

        return response()->json([
            'message' => 'Conversation deleted.',
            'conversation_id' => $conversationId,
            'deleted_at' => $record->deleted_at,
        ]);
    }
}