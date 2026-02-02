<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageConversationDeletion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StudentMessageController extends Controller
{
    private function isCounselor(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));
        return str_contains($role, 'counselor') || str_contains($role, 'counsellor') || str_contains($role, 'guidance');
    }

    private function isAdmin(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));
        return str_contains($role, 'admin');
    }

    /**
     * Normalize role strings into stable canonical values to avoid mismatches that
     * create duplicate threads (e.g., "Counsellor", "counselor", "Counselor", etc).
     */
    private function normalizeRole(?string $role): string
    {
        $r = strtolower(trim((string) $role));
        $r = str_replace([' ', '-'], ['_', '_'], $r);

        if ($r === '') return '';

        // Common contains-based normalization (covers "guidance_counselor", etc.)
        if (str_contains($r, 'counselor') || str_contains($r, 'counsellor') || str_contains($r, 'guidance')) return 'counselor';
        if (str_contains($r, 'admin')) return 'admin';
        if (str_contains($r, 'student')) return 'student';
        if (str_contains($r, 'guest')) return 'guest';

        // Referral user variants
        if ($r === 'referral_user' || $r === 'referraluser' || $r === 'referral') return 'referral_user';

        // Other known system-ish roles
        if ($r === 'system') return 'system';

        return $r;
    }

    /**
     * Canonical student thread id.
     *
     * ✅ This is the single source of truth for the student's conversation_id.
     * All student messages (incoming/outgoing) are normalized to this value to
     * prevent duplicate threads when other senders use different ids.
     */
    private function canonicalStudentConversationId(User $user): string
    {
        return 'student-' . (int) $user->id;
    }

    /**
     * Map legacy student UI keys to the canonical student conversation_id.
     *
     * Old Student UI logic derived keys like:
     * - counselor-{id}
     * - counselor-office
     *
     * We treat those as synonyms of the canonical student thread so deletions
     * remain respected after the change.
     */
    private function mapLegacyStudentKeyToCanonical(string $rawKey, string $canonicalStudentKey): string
    {
        $k = trim($rawKey);
        if ($k === '') return '';

        $lk = strtolower($k);

        // Already canonical
        if ($lk === strtolower($canonicalStudentKey)) {
            return $canonicalStudentKey;
        }

        // Legacy keys produced by Student UI (counselor)
        if ($lk === 'counselor-office') {
            return $canonicalStudentKey;
        }
        if (str_starts_with($lk, 'counselor-')) {
            return $canonicalStudentKey;
        }

        // Legacy keys produced by Student UI (admin)
        if ($lk === 'admin-office' || $lk === 'administrator-office') {
            return $canonicalStudentKey;
        }
        if (str_starts_with($lk, 'admin-') || str_starts_with($lk, 'administrator-')) {
            return $canonicalStudentKey;
        }

        // If older variants existed (e.g., "student_{id}" or "student {id}")
        $lk2 = str_replace([' ', '_'], ['-', '-'], $lk);
        if (str_starts_with($lk2, 'student-')) {
            return $lk2;
        }

        return $k;
    }

    private function hasMessagesColumn(string $col): bool
    {
        try {
            return Schema::hasColumn('messages', $col);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * GET /student/messages
     *
     * Fetch all messages for the authenticated student/guest.
     *
     * ✅ FIXED:
     * - Enforce canonical conversation_id ("student-{id}") for ALL returned rows
     *   to prevent duplicate threads on the frontend.
     * - Normalize sender/recipient_role values to stable canonical roles.
     * - Respect conversation deletions: if deleted at time T, only show messages created AFTER T.
     *   (Also respects legacy deletion keys like "counselor-office", "counselor-{id}", "admin-office", "admin-{id}".)
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $canonicalConversationId = $this->canonicalStudentConversationId($user);

        // Load deletion cutoffs for this user and reduce into a single cutoff for the canonical student thread.
        $deletions = MessageConversationDeletion::query()
            ->where('user_id', (int) $user->id)
            ->whereNotNull('deleted_at')
            ->get(['conversation_id', 'deleted_at']);

        $cutoff = null; /** @var Carbon|null $cutoff */
        foreach ($deletions as $d) {
            $rawKey = trim((string) ($d->conversation_id ?? ''));
            if ($rawKey === '') continue;

            $mappedKey = $this->mapLegacyStudentKeyToCanonical($rawKey, $canonicalConversationId);

            // Only apply deletions that resolve to the canonical student thread
            if (strtolower($mappedKey) !== strtolower($canonicalConversationId)) {
                continue;
            }

            if (! $d->deleted_at) continue;

            if (! $cutoff) {
                $cutoff = $d->deleted_at;
            } else {
                if ($d->deleted_at->gt($cutoff)) {
                    $cutoff = $d->deleted_at;
                }
            }
        }

        // Fetch messages owned by this student.
        $query = Message::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');

        if ($cutoff) {
            $query->where('created_at', '>', $cutoff);
        }

        $messages = $query->get();

        // Enforce canonical conversation_id + normalize roles in the payload
        $messages->transform(function (Message $m) use ($canonicalConversationId) {
            $m->conversation_id = $canonicalConversationId;

            $m->sender = $this->normalizeRole($m->sender);
            $m->recipient_role = $this->normalizeRole($m->recipient_role);

            return $m;
        });

        return response()->json([
            'message'  => 'Fetched your messages.',
            'messages' => $messages->values(),
            'canonical_conversation_id' => $canonicalConversationId,
            'deleted_cutoff' => $cutoff ? $cutoff->toISOString() : null,
        ]);
    }

    /**
     * POST /student/messages
     *
     * Create a new message authored by the current student OR guest.
     *
     * ✅ UPDATED:
     * - Students can message counselors OR admins only.
     * - Guests can message counselors only (keeps current behavior unless you want guests to message admins too).
     * - Enforce canonical conversation_id ("student-{id}") ALWAYS.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'content' => ['required', 'string'],

            // UI may send these; keep optional but normalize safely.
            'recipient_role' => ['nullable', 'string', 'max:50'],
            'recipient_id'   => ['nullable', 'integer', 'exists:users,id'],

            // Accept string OR integer conversation ids from the frontend (ignored; we enforce canonical).
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

        $userRole = $this->normalizeRole((string) ($user->role ?? 'student'));
        $senderRole = ($userRole === 'guest') ? 'guest' : 'student';

        // Default recipient is counselor (existing behavior)
        $recipientRoleInput = $this->normalizeRole($data['recipient_role'] ?? '');
        if ($recipientRoleInput === '') $recipientRoleInput = 'counselor';

        // ✅ Enforce allowed recipients:
        // - student: counselor OR admin
        // - guest: counselor only (change if you want guests to message admins too)
        if ($senderRole === 'guest') {
            if ($recipientRoleInput !== 'counselor') {
                return response()->json([
                    'message' => 'Invalid recipient_role. Guests can only message counselors.',
                ], 422);
            }
        } else {
            if (! in_array($recipientRoleInput, ['counselor', 'admin'], true)) {
                return response()->json([
                    'message' => 'Invalid recipient_role. Students can only message counselors or admins.',
                ], 422);
            }
        }

        $recipientId = isset($data['recipient_id']) ? (int) $data['recipient_id'] : null;

        // If a specific recipient is provided, verify they are actually the intended role.
        if ($recipientId) {
            $recipientUser = User::find($recipientId);

            if (! $recipientUser) {
                return response()->json(['message' => 'Recipient not found.'], 422);
            }

            if ($recipientRoleInput === 'counselor' && ! $this->isCounselor($recipientUser)) {
                return response()->json(['message' => 'Recipient is not a counselor.'], 422);
            }

            if ($recipientRoleInput === 'admin' && ! $this->isAdmin($recipientUser)) {
                return response()->json(['message' => 'Recipient is not an admin.'], 422);
            }
        }

        // ✅ Enforce canonical conversation id for student thread
        $conversationId = $this->canonicalStudentConversationId($user);

        $message = new Message();
        $message->user_id = (int) $user->id;

        $message->sender = $senderRole;
        $message->sender_id = (int) $user->id;
        $message->sender_name = $user->name ?? null;

        $message->recipient_role = $recipientRoleInput;
        $message->recipient_id = $recipientId; // null = role inbox; int = direct recipient

        $message->conversation_id = $conversationId;

        $message->content = $data['content'];

        // Student has already "read" their own outgoing message (student-side read flag)
        $message->is_read = true;
        $message->student_read_at = now();

        // ✅ Recipient-side unread flags
        // Counselor flags (existing columns expected)
        $message->counselor_is_read = ($recipientRoleInput === 'counselor') ? false : true;
        $message->counselor_read_at = ($recipientRoleInput === 'counselor') ? null : now();

        // Admin flags (set ONLY if columns exist to avoid SQL errors)
        if ($this->hasMessagesColumn('admin_is_read')) {
            $message->admin_is_read = ($recipientRoleInput === 'admin') ? false : true;
        }
        if ($this->hasMessagesColumn('admin_read_at')) {
            $message->admin_read_at = ($recipientRoleInput === 'admin') ? null : now();
        }
        // Some schemas may use alternate naming
        if ($this->hasMessagesColumn('is_read_by_admin')) {
            $message->is_read_by_admin = ($recipientRoleInput === 'admin') ? false : true;
        }
        if ($this->hasMessagesColumn('read_by_admin')) {
            $message->read_by_admin = ($recipientRoleInput === 'admin') ? false : true;
        }

        $message->save();

        // Normalize payload roles (defensive)
        $message->sender = $this->normalizeRole($message->sender);
        $message->recipient_role = $this->normalizeRole($message->recipient_role);
        $message->conversation_id = $conversationId;

        return response()->json([
            'message' => 'Your message has been sent.',
            'messageRecord' => $message,
            'canonical_conversation_id' => $conversationId,
        ], 201);
    }

    /**
     * POST /student/messages/mark-as-read
     *
     * Marks messages as read for the student/guest.
     * Only affects messages belonging to current user.
     *
     * - If message_ids omitted/empty => mark all as read.
     *
     * ✅ UPDATED:
     * - Also mark admin messages as read for the student.
     */
    public function markAsRead(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'message_ids' => ['nullable', 'array'],
            'message_ids.*' => ['integer'],
        ]);

        $query = Message::query()
            ->where('user_id', $user->id)
            ->where('is_read', false);

        // Typically only counselor/admin/system messages are unread for student
        $query->whereIn(DB::raw('LOWER(sender)'), ['counselor', 'admin', 'system']);

        $messageIds = $data['message_ids'] ?? null;

        if (is_array($messageIds) && count($messageIds) > 0) {
            $query->whereIn('id', $messageIds);
        }

        $updatedCount = $query->update([
            'is_read' => true,
            'student_read_at' => now(),
        ]);

        return response()->json([
            'message' => 'Messages marked as read.',
            'updated_count' => $updatedCount,
        ]);
    }
}