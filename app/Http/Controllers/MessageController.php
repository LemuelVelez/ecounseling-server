<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    private function isAdmin(?User $user): bool
    {
        if (! $user) return false;
        $role = strtolower((string) ($user->role ?? ''));
        return str_contains($role, 'admin');
    }

    /**
     * Only allow editing/deleting your own message:
     * - Admin can modify anything
     * - If sender_id exists => must match current user id
     * - Else fallback (legacy): user_id must match current user id
     */
    private function canModify(?User $user, Message $message): bool
    {
        if (! $user) return false;
        if ($this->isAdmin($user)) return true;

        // Never allow editing system messages
        if (strtolower((string) ($message->sender ?? '')) === 'system') return false;

        // Preferred: sender_id ownership
        if ($message->sender_id !== null) {
            return (int) $message->sender_id === (int) $user->id;
        }

        // Legacy fallback: thread owner (student/guest messages sometimes use user_id)
        if ($message->user_id !== null) {
            return (int) $message->user_id === (int) $user->id;
        }

        return false;
    }

    /**
     * PATCH/PUT /messages/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $message = Message::query()->find($id);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        if (! $this->canModify($user, $message)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $message->content = $validated['content'];
        $message->save();

        return response()->json([
            'message' => 'Message updated.',
            'messageRecord' => $message,
        ]);
    }

    /**
     * DELETE /messages/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $message = Message::query()->find($id);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        if (! $this->canModify($user, $message)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Hard delete (simple + matches your frontend "delete message")
        $message->delete();

        return response()->json([
            'message' => 'Message deleted.',
            'id' => $id,
        ]);
    }
}