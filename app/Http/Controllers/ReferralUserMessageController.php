<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use App\Models\MessageConversationDeletion;
use Illuminate\Database\Eloquent\Builder;
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
            || str_contains($role, 'referral-user')
            || str_contains($role, 'referral user')
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

    private function isAdmin(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));

        return str_contains($role, 'admin');
    }

    private function isCounselorSenderValue(?string $sender): bool
    {
        $s = strtolower(trim((string) $sender));
        return $s === 'counselor'
            || str_contains($s, 'counselor')
            || str_contains($s, 'counsellor')
            || str_contains($s, 'guidance');
    }

    private function isAdminSenderValue(?string $sender): bool
    {
        $s = strtolower(trim((string) $sender));
        return $s === 'admin' || str_contains($s, 'admin');
    }

    /**
     * ✅ Referral-user sender variants seen in older data.
     */
    private function isReferralSenderValue(?string $sender): bool
    {
        $s = strtolower(trim((string) $sender));
        if ($s === '') return false;

        $norm = str_replace(['-', ' '], ['_', '_'], $s);

        return $norm === 'referral_user'
            || str_contains($norm, 'referral_user')
            || str_contains($s, 'dean')
            || str_contains($s, 'registrar')
            || str_contains($s, 'program chair')
            || str_contains($norm, 'program_chair')
            || str_contains($s, 'programchair')
            || str_contains($s, 'chair')
            || str_contains($s, 'chairperson');
    }

    /**
     * ✅ Normalize sender role so frontend can reliably filter/group:
     * - dean/registrar/program_chair/... => referral_user
     * - guidance/counsellor/... => counselor
     * - admin variants => admin
     */
    private function normalizeSenderRole(?string $sender): string
    {
        $s = strtolower(trim((string) $sender));
        if ($s === '') return '';

        if ($this->isAdminSenderValue($s)) return 'admin';
        if ($this->isCounselorSenderValue($s)) return 'counselor';
        if ($this->isReferralSenderValue($s)) return 'referral_user';

        return $s;
    }

    /**
     * ✅ Referral-user role variants seen in older data.
     * IMPORTANT: normalize to "referral_user" so the frontend's isVisibleForMe() won't drop them.
     */
    private function normalizeRecipientRole(?string $role): string
    {
        $r = strtolower(trim((string) $role));
        if ($r === '') return '';

        $norm = str_replace(['-', ' '], ['_', '_'], $r);

        if (
            $norm === 'referral_user' ||
            str_contains($norm, 'referral_user') ||
            in_array($r, [
                'referral_user',
                'referral-user',
                'referral user',
                'dean',
                'registrar',
                'program_chair',
                'program chair',
                'programchair',
                'chair',
                'chairperson',
            ], true)
        ) {
            return 'referral_user';
        }

        return $r;
    }

    /**
     * ✅ Canonical conversation id for referral_user <-> {counselor|admin}
     * This MUST be stable so replies never create a "new thread".
     */
    private function conversationIdFor(int $referralUserId, string $peerRole, int $peerId): string
    {
        $peerRole = strtolower(trim($peerRole));
        if (! in_array($peerRole, ['counselor', 'admin'], true)) {
            $peerRole = 'counselor';
        }

        return "referral_user-{$referralUserId}-{$peerRole}-{$peerId}";
    }

    /**
     * ✅ Only treat a stored conversation_id as canonical if it matches:
     *    referral_user-{refId}-(counselor|admin)-{peerId}
     */
    private function isCanonicalConversationId(?string $conversationId): bool
    {
        $s = trim((string) $conversationId);
        if ($s === '') return false;

        return (bool) preg_match('/^referral_user-\d+-(counselor|admin)-\d+$/', $s);
    }

    /**
     * Compute the canonical conversation id for ANY message in this module,
     * even if older rows have NULL/empty/legacy conversation_id.
     */
    private function conversationKey(Message $m): string
    {
        $raw = $m->conversation_id ?? null;

        // ✅ Accept only canonical stored ids; ignore legacy ids to prevent splitting.
        if ($raw !== null) {
            $rawStr = trim((string) $raw);
            if ($this->isCanonicalConversationId($rawStr)) {
                return $rawStr;
            }
        }

        $senderKind = $this->normalizeSenderRole($m->sender ?? '');

        $senderId = (int) ($m->sender_id ?? 0);
        $recipientId = (int) ($m->recipient_id ?? 0);
        $ownerId = (int) ($m->user_id ?? 0);

        $recipientRoleNorm = $this->normalizeRecipientRole($m->recipient_role ?? '');

        // referral_user -> counselor/admin
        if ($senderKind === 'referral_user' && in_array($recipientRoleNorm, ['counselor', 'admin'], true)) {
            $refId = $senderId > 0 ? $senderId : ($ownerId > 0 ? $ownerId : 0);
            if ($refId > 0 && $recipientId > 0) {
                return $this->conversationIdFor($refId, $recipientRoleNorm, $recipientId);
            }
        }

        // counselor/admin -> referral_user
        if (in_array($senderKind, ['counselor', 'admin'], true) && $recipientRoleNorm === 'referral_user') {
            $refId = $recipientId > 0 ? $recipientId : ($ownerId > 0 ? $ownerId : 0);
            $peerId = $senderId > 0 ? $senderId : 0;

            if ($refId > 0 && $peerId > 0) {
                return $this->conversationIdFor($refId, $senderKind, $peerId);
            }
        }

        // legacy-safe fallback (keeps it unique to this referral user)
        if ($ownerId > 0) return "referral_user-{$ownerId}";

        return 'referral_user-office';
    }

    private function resolveDisplayName(?User $u, ?string $fallback): ?string
    {
        $name = $u?->name;
        if ($name && trim($name) !== '') return $name;
        if ($fallback && trim($fallback) !== '') return $fallback;
        return null;
    }

    private function applyRecipientIsReferralUser(Builder $q): Builder
    {
        // robust to older recipient_role variants
        return $q->where(function ($rr) {
            $rr->where('recipient_role', 'referral_user')
               ->orWhere('recipient_role', 'referral-user')
               ->orWhere('recipient_role', 'referral user')
               ->orWhere('recipient_role', 'dean')
               ->orWhere('recipient_role', 'registrar')
               ->orWhere('recipient_role', 'program_chair')
               ->orWhere('recipient_role', 'program chair')
               ->orWhere('recipient_role', 'programchair')
               ->orWhere('recipient_role', 'chair')
               ->orWhere('recipient_role', 'chairperson')
               ->orWhereRaw("LOWER(REPLACE(REPLACE(recipient_role,'-','_'),' ','_')) LIKE ?", ['%referral_user%']);
        });
    }

    private function applySenderIsReferralUser(Builder $q): Builder
    {
        // robust to older sender values used by referral-user roles (dean/registrar/etc)
        return $q->where(function ($s) {
            $s->where('sender', 'referral_user')
              ->orWhere('sender', 'referral-user')
              ->orWhere('sender', 'referral user')
              ->orWhere('sender', 'dean')
              ->orWhere('sender', 'registrar')
              ->orWhere('sender', 'program_chair')
              ->orWhere('sender', 'program chair')
              ->orWhere('sender', 'programchair')
              ->orWhere('sender', 'chair')
              ->orWhere('sender', 'chairperson')
              ->orWhereRaw("LOWER(REPLACE(REPLACE(sender,'-','_'),' ','_')) LIKE ?", ['%referral_user%'])
              ->orWhereRaw('LOWER(sender) LIKE ?', ['%dean%'])
              ->orWhereRaw('LOWER(sender) LIKE ?', ['%registrar%'])
              ->orWhereRaw('LOWER(sender) LIKE ?', ['%program chair%'])
              ->orWhereRaw("LOWER(REPLACE(REPLACE(sender,'-','_'),' ','_')) LIKE ?", ['%program_chair%'])
              ->orWhereRaw('LOWER(sender) LIKE ?', ['%programchair%'])
              ->orWhereRaw('LOWER(sender) LIKE ?', ['%chair%']);
        });
    }

    private function applySenderIsCounselorOrAdmin(Builder $q): Builder
    {
        // counselor OR admin senders (and legacy variants)
        return $q->where(function ($q3) {
            $q3->where('sender', 'counselor')
               ->orWhere('sender', 'admin')
               ->orWhereRaw('LOWER(sender) LIKE ?', ['%counselor%'])
               ->orWhereRaw('LOWER(sender) LIKE ?', ['%counsellor%'])
               ->orWhereRaw('LOWER(sender) LIKE ?', ['%guidance%'])
               ->orWhereRaw('LOWER(sender) LIKE ?', ['%admin%']);
        });
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

        /**
         * ✅ CRITICAL:
         * Always return stable canonical values that the frontend expects:
         * - conversation_id: canonical thread id
         * - recipient_role: normalize dean/registrar/... => referral_user
         * - sender: normalize dean/registrar/... => referral_user; guidance/... => counselor; admin variants => admin
         */
        $dto['conversation_id'] = $this->conversationKey($m);

        $senderNorm = $this->normalizeSenderRole($m->sender ?? null);
        if ($senderNorm !== '') {
            $dto['sender'] = $senderNorm;
        }

        $recNorm = $this->normalizeRecipientRole($m->recipient_role ?? null);
        if ($recNorm !== '') {
            $dto['recipient_role'] = $recNorm;
        }

        // ✅ Legacy safety: if old rows missed sender_id/recipient_id, provide safe fallbacks
        $ownerId = (int) ($m->user_id ?? 0);

        if (($dto['sender'] ?? null) === 'referral_user') {
            $sid = (int) ($m->sender_id ?? 0);
            if ($sid <= 0 && $ownerId > 0) {
                $dto['sender_id'] = $ownerId;
            }
        }

        if (($dto['recipient_role'] ?? null) === 'referral_user') {
            $rid = (int) ($m->recipient_id ?? 0);
            if ($rid <= 0 && $ownerId > 0) {
                $dto['recipient_id'] = $ownerId;
            }
        }

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

        // Optional convenience: expose recipient_name if missing (helps UI titles)
        if ((!isset($dto['recipient_name']) || trim((string) ($dto['recipient_name'] ?? '')) === '') && $recipientUser) {
            $dto['recipient_name'] = $recipientUser->name;
        }

        /**
         * ✅ EXTRA (safe) metadata:
         * Helps any client reliably know which peer this thread is with.
         * (Does not break existing clients; just additional fields.)
         */
        $cid = (string) ($dto['conversation_id'] ?? '');
        if ($this->isCanonicalConversationId($cid)) {
            if (preg_match('/^referral_user-(\d+)-(counselor|admin)-(\d+)$/', $cid, $mm)) {
                $dto['peer_role'] = $mm[2];
                $dto['peer_id'] = (int) $mm[3];

                // peer_name best-effort
                if (($dto['sender'] ?? null) === 'referral_user') {
                    $dto['peer_name'] = $this->resolveDisplayName($recipientUser, (string) ($dto['recipient_name'] ?? ''));
                } else {
                    $dto['peer_name'] = $this->resolveDisplayName($senderUser, (string) ($dto['sender_name'] ?? ''));
                }
            }
        }

        return $dto;
    }

    /**
     * GET /referral-user/messages
     *
     * Privacy enforcement:
     * - Referral user sees only:
     *   (A) Messages they sent to counselors/admins
     *   (B) Messages counselors/admins sent to them
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
        $legacyPrefixes = [];

        foreach ($deletions as $d) {
            $key = trim((string) ($d->conversation_id ?? ''));
            if ($key === '') continue;

            $deletedAtByConversation[$key] = $d->deleted_at;

            // ✅ Backward compatibility:
            // If a deletion was stored as "referral_user-{id}", treat it as a prefix for canonical:
            // "referral_user-{id}-{peerRole}-{peerId}"
            if (preg_match('/^referral_user-\d+$/', $key)) {
                $legacyPrefixes[$key] = $d->deleted_at;
            }
        }

        $messages = Message::query()
            ->with([
                'senderUser:id,name,avatar_url',
                'recipientUser:id,name,avatar_url',
            ])
            ->where(function (Builder $q) use ($user) {
                // (A) Sent by this referral user -> counselor/admin only
                $q->where(function (Builder $q2) use ($user) {
                    $this->applySenderIsReferralUser($q2);

                    // Prefer sender_id, but legacy rows may rely on owner user_id
                    $q2->where(function (Builder $id) use ($user) {
                        $id->where('sender_id', (int) $user->id)
                           ->orWhere(function (Builder $legacy) use ($user) {
                               $legacy->whereNull('sender_id')
                                      ->where('user_id', (int) $user->id);
                           });
                    });

                    $q2->whereIn('recipient_role', ['counselor', 'admin']);
                })

                // (B) Sent by counselor/admin -> this referral user only
                ->orWhere(function (Builder $q2) use ($user) {
                    $this->applyRecipientIsReferralUser($q2);

                    $q2->where(function (Builder $id) use ($user) {
                        $id->where('recipient_id', (int) $user->id)
                           ->orWhere(function (Builder $legacy) use ($user) {
                               $legacy->whereNull('recipient_id')
                                      ->where('user_id', (int) $user->id);
                           });
                    });

                    $this->applySenderIsCounselorOrAdmin($q2);
                });
            })
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $visible = $messages->filter(function (Message $m) use ($deletedAtByConversation, $legacyPrefixes) {
            $key = $this->conversationKey($m);

            // exact match (canonical)
            $cutoff = $deletedAtByConversation[$key] ?? null;

            // prefix match (legacy deletion key: referral_user-{id})
            if (! $cutoff && count($legacyPrefixes) > 0) {
                foreach ($legacyPrefixes as $prefix => $dt) {
                    if (str_starts_with($key, $prefix . '-')) {
                        $cutoff = $dt;
                        break;
                    }
                }
            }

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
     * Referral user can message a specific counselor OR admin (direct only).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isReferralUser($user)) return response()->json(['message' => 'Forbidden.'], 403);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'recipient_id' => ['required', 'integer', 'exists:users,id'],

            // optional; server will compute canonical regardless
            'recipient_role' => ['nullable', 'string', 'max:50'],
            'conversation_id' => ['nullable', 'string', 'max:255'],
        ]);

        $peerId = (int) $data['recipient_id'];
        $peer = User::find($peerId);

        if (! $peer) {
            return response()->json(['message' => 'Recipient not found.'], 422);
        }

        $peerRole = null;

        if ($this->isCounselor($peer)) {
            $peerRole = 'counselor';
        } elseif ($this->isAdmin($peer)) {
            $peerRole = 'admin';
        } else {
            return response()->json(['message' => 'Recipient must be a counselor or admin.'], 422);
        }

        $conversationId = $this->conversationIdFor((int) $user->id, $peerRole, $peerId);

        $m = new Message();
        $m->user_id = (int) $user->id; // owner = referral user

        $m->sender = 'referral_user';
        $m->sender_id = (int) $user->id;
        $m->sender_name = $user->name ?? null;

        $m->recipient_role = $peerRole;
        $m->recipient_id = $peerId;

        // ✅ Always store canonical
        $m->conversation_id = $conversationId;

        $m->content = (string) $data['content'];

        // referral user already read their outgoing
        $m->is_read = true;
        $m->student_read_at = now();

        // staff recipient hasn't read yet (use counselor_is_read for both counselor/admin recipients)
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

        $q = Message::query();

        $this->applyRecipientIsReferralUser($q);

        $q->where('recipient_id', (int) $user->id)
          ->where(function ($w) {
              $w->where('is_read', false)
                ->orWhere('is_read', 0)
                ->orWhereNull('is_read');
          });

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
     * Referral user can only edit their OWN outgoing messages (to counselor/admin).
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
        $senderNorm = $this->normalizeSenderRole($m->sender ?? null);
        if ($senderNorm !== 'referral_user' || (int) ($m->sender_id ?? 0) !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $recRole = strtolower(trim((string) ($m->recipient_role ?? '')));
        if (! in_array($recRole, ['counselor', 'admin'], true)) {
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
     * Referral user can only delete their OWN outgoing messages (to counselor/admin).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (! $user) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isReferralUser($user)) return response()->json(['message' => 'Forbidden.'], 403);

        $m = Message::query()->find($id);
        if (! $m) return response()->json(['message' => 'Message not found.'], 404);

        $senderNorm = $this->normalizeSenderRole($m->sender ?? null);
        if ($senderNorm !== 'referral_user' || (int) ($m->sender_id ?? 0) !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $recRole = strtolower(trim((string) ($m->recipient_role ?? '')));
        if (! in_array($recRole, ['counselor', 'admin'], true)) {
            return response()->json(['message' => 'Invalid message recipient.'], 422);
        }

        $m->delete();

        return response()->json([
            'message' => 'Message deleted.',
            'id' => $id,
        ]);
    }
}