<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use App\Models\MessageConversationDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReferralUserMessageController extends Controller
{
    private function isReferralUser(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));

        return str_contains($role, 'referral_user')
            || str_contains($role, 'dean')
            || str_contains($role, 'registrar')
            || str_contains($role, 'program chair')
            || str_contains($role, 'program_chair')
            || str_contains($role, 'programchair')
            || str_contains($role, 'chair');
    }

    private function isCounselor(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));

        return str_contains($role, 'counselor')
            || str_contains($role, 'counsellor')
            || str_contains($role, 'guidance');
    }

    private function isCounselorSenderValue(?string $sender): bool
    {
        $s = strtolower(trim((string) $sender));
        return $s === 'counselor' || str_contains($s, 'counselor') || str_contains($s, 'counsellor') || str_contains($s, 'guidance');
    }

    /**
     * Canonical conversation id for referral_user <-> counselor
     */
    private function conversationIdFor(int $referralUserId, int $counselorId): string
    {
        return "referral_user-{$referralUserId}-counselor-{$counselorId}";
    }

    private function conversationKey(Message $m): string
    {
        $raw = $m->conversation_id ?? null;
        if ($raw !== null && trim((string) $raw) !== '') return (string) $raw;

        // Best-effort canonical fallback if conversation_id is missing:
        $sender = strtolower(trim((string) ($m->sender ?? '')));

        $senderId = (int) ($m->sender_id ?? 0);
        $recipientId = (int) ($m->recipient_id ?? 0);
        $recipientRole = strtolower(trim((string) ($m->recipient_role ?? '')));

        // referral_user -> counselor
        if ($sender === 'referral_user' && $recipientRole === 'counselor' && $senderId > 0 && $recipientId > 0) {
            return $this->conversationIdFor($senderId, $recipientId);
        }

        // counselor -> referral_user
        if ($this->isCounselorSenderValue($sender) && $recipientRole === 'referral_user' && $senderId > 0 && $recipientId > 0) {
            return $this->conversationIdFor($recipientId, $senderId);
        }

        // legacy-safe fallback (keeps it unique to this referral user)
        $owner = (int) ($m->user_id ?? 0);
        if ($owner > 0) return "referral_user-{$owner}";

        return 'referral_user-office';
    }

    private function resolveDisplayName(?User $u, ?string $fallback): ?string
    {
        $name = $u?->name;
        if ($name && trim($name) !== '') return $name;
        if ($fallback && trim($fallback) !== '') return $fallback;
        return null;
    }

    private function toApiDto(Message $m): array
    {
        // Attach related users (if loaded)
        $senderUser = $m->relationLoaded('senderUser') ? $m->senderUser : null;
        $recipientUser = $m->relationLoaded('recipientUser') ? $m->recipientUser : null;

        $senderName = $m->sender_name;

        // If missing, resolve from related sender user
        if (! $senderName || trim((string) $senderName) === '') {
            $senderName = $this->resolveDisplayName($senderUser, null);
        }

        $dto = $m->toArray();

        $dto['sender_name'] = $senderName;

        // Optional convenience for UI
        $dto['sender_user'] = $senderUser ? [
            'id' => $senderUser->id,
            'name' => $senderUser->name,
            'avatar_url' => $senderUser->avatar_url,
        ] : null;

        $dto['recipient_user'] = $recipientUser ? [
            'id' => $recipientUser->id,
            'name' => $recipientUser->name,
            'avatar_url' => $recipientUser->avatar_url,
        ] : null;

        return $dto;
    }

    /**
     * GET /referral-user/messages
     *
     * Privacy enforcement:
     * - Referral user sees only:
     *   (A) Messages they sent to counselors
     *   (B) Messages counselors sent to them
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isReferralUser($user)) return response()->json(['message' => 'Forbidden.'], 403);

        $deletions = MessageConversationDeletion::query()
            ->where('user_id', (int) $user->id)
            ->whereNotNull('deleted_at')
            ->get(['conversation_id', 'deleted_at']);

        $deletedAtByConversation = [];
        foreach ($deletions as $d) {
            $key = trim((string) ($d->conversation_id ?? ''));
            if ($key === '') continue;
            $deletedAtByConversation[$key] = $d->deleted_at;
        }

        $messages = Message::query()
            ->with([
                'senderUser:id,name,avatar_url',
                'recipientUser:id,name,avatar_url',
            ])
            ->where(function ($q) use ($user) {
                // (A) Sent by this referral user -> counselor only
                $q->where(function ($q2) use ($user) {
                    $q2->where('sender', 'referral_user')
                        ->where('sender_id', (int) $user->id)
                        ->where('recipient_role', 'counselor');
                })
                // (B) Sent by counselor -> this referral user only
                ->orWhere(function ($q2) use ($user) {
                    $q2->where('recipient_role', 'referral_user')
                        ->where('recipient_id', (int) $user->id)
                        ->where(function ($q3) {
                            $q3->where('sender', 'counselor')
                               ->orWhereRaw('LOWER(sender) LIKE ?', ['%counselor%'])
                               ->orWhereRaw('LOWER(sender) LIKE ?', ['%counsellor%'])
                               ->orWhereRaw('LOWER(sender) LIKE ?', ['%guidance%']);
                        });
                });
            })
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $visible = $messages->filter(function (Message $m) use ($deletedAtByConversation) {
            $key = $this->conversationKey($m);

            if (! array_key_exists($key, $deletedAtByConversation)) return true;

            $cutoff = $deletedAtByConversation[$key] ?? null;
            if (! $cutoff) return true;

            $createdAt = $m->created_at instanceof Carbon
                ? $m->created_at
                : Carbon::parse((string) $m->created_at);

            return $createdAt->gt($cutoff);
        })->values();

        return response()->json([
            'message' => 'Fetched your messages.',
            'messages' => $visible->map(fn (Message $m) => $this->toApiDto($m))->all(),
        ]);
    }

    /**
     * POST /referral-user/messages
     * Referral user can message a specific counselor (direct only).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isReferralUser($user)) return response()->json(['message' => 'Forbidden.'], 403);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $counselorId = (int) $data['recipient_id'];

        $counselor = User::find($counselorId);
        if (! $counselor || ! $this->isCounselor($counselor)) {
            return response()->json(['message' => 'Recipient is not a counselor.'], 422);
        }

        $conversationId = $this->conversationIdFor((int) $user->id, $counselorId);

        $m = new Message();
        $m->user_id = (int) $user->id; // owner = referral user

        $m->sender = 'referral_user';
        $m->sender_id = (int) $user->id;
        $m->sender_name = $user->name ?? null;

        $m->recipient_role = 'counselor';
        $m->recipient_id = $counselorId;

        $m->conversation_id = $conversationId;
        $m->content = (string) $data['content'];

        // referral user already read their outgoing
        $m->is_read = true;
        $m->student_read_at = now();

        // counselor hasn't read
        $m->counselor_is_read = false;
        $m->counselor_read_at = null;

        $m->save();

        $m->load([
            'senderUser:id,name,avatar_url',
            'recipientUser:id,name,avatar_url',
        ]);

        return response()->json([
            'message' => 'Message sent.',
            'messageRecord' => $this->toApiDto($m),
        ], 201);
    }

    /**
     * POST /referral-user/messages/mark-as-read
     * Marks incoming messages (to referral_user) as read.
     */
    public function markAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isReferralUser($user)) return response()->json(['message' => 'Forbidden.'], 403);

        $data = $request->validate([
            'message_ids'   => ['nullable', 'array'],
            'message_ids.*' => ['integer'],
        ]);

        $q = Message::query()
            ->where('recipient_role', 'referral_user')
            ->where('recipient_id', (int) $user->id)
            ->where('is_read', false);

        $ids = $data['message_ids'] ?? null;
        if (is_array($ids) && count($ids) > 0) {
            $q->whereIn('id', $ids);
        }

        $updated = $q->update([
            'is_read' => true,
            'student_read_at' => now(),
        ]);

        return response()->json([
            'message' => 'Messages marked as read.',
            'updated_count' => $updated,
        ]);
    }

    /**
     * PATCH/PUT /referral-user/messages/{id}
     * Referral user can only edit their OWN outgoing messages.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isReferralUser($user)) return response()->json(['message' => 'Forbidden.'], 403);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $m = Message::query()->find($id);
        if (! $m) return response()->json(['message' => 'Message not found.'], 404);

        // Only edit own referral_user-sent message
        if (strtolower((string) $m->sender) !== 'referral_user' || (int) $m->sender_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Also ensure it is a counselor conversation
        if (strtolower((string) $m->recipient_role) !== 'counselor') {
            return response()->json(['message' => 'Invalid message recipient.'], 422);
        }

        $m->content = (string) $data['content'];
        $m->save();

        $m->load([
            'senderUser:id,name,avatar_url',
            'recipientUser:id,name,avatar_url',
        ]);

        return response()->json([
            'message' => 'Message updated.',
            'messageRecord' => $this->toApiDto($m),
        ]);
    }

    /**
     * DELETE /referral-user/messages/{id}
     * Referral user can only delete their OWN outgoing messages.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isReferralUser($user)) return response()->json(['message' => 'Forbidden.'], 403);

        $m = Message::query()->find($id);
        if (! $m) return response()->json(['message' => 'Message not found.'], 404);

        if (strtolower((string) $m->sender) !== 'referral_user' || (int) $m->sender_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (strtolower((string) $m->recipient_role) !== 'counselor') {
            return response()->json(['message' => 'Invalid message recipient.'], 422);
        }

        $m->delete();

        return response()->json([
            'message' => 'Message deleted.',
            'id' => $id,
        ]);
    }
}