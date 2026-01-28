<?php

namespace App\Http\Controllers;

use App\Models\IntakeRequest;
use App\Models\Message;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private function isCounselor(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));

        return str_contains($role, 'counselor')
            || str_contains($role, 'counsellor')
            || str_contains($role, 'guidance');
    }

    private function isReferralUser(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));

        return str_contains($role, 'dean')
            || str_contains($role, 'registrar')
            || str_contains($role, 'program chair')
            || str_contains($role, 'program_chair')
            || str_contains($role, 'programchair');
    }

    /**
     * Make this endpoint resilient:
     * If a table/model is not migrated yet, do not 500 the whole endpoint.
     */
    private function safeCount(callable $fn): int
    {
        try {
            $v = $fn();
            return is_numeric($v) ? (int) $v : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * GET /notifications/counts
     *
     * Returns notification badges:
     * - unread_messages
     * - pending_appointments (counselor)
     * - new_referrals (counselor + referral users)
     *
     * ✅ IMPORTANT:
     * We expose counts both:
     * - as top-level keys (what your frontend expects)
     * - and inside "counts" (backward compatibility)
     */
    public function counts(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $unreadMessages = 0;
        $pendingAppointments = 0;
        $newReferrals = 0;

        if ($this->isCounselor($user)) {
            $unreadMessages = $this->safeCount(function () use ($user) {
                return Message::query()
                    ->where('recipient_role', 'counselor')
                    ->where('counselor_is_read', false)
                    ->where(function ($q) use ($user) {
                        $q->whereNull('recipient_id')
                          ->orWhere('recipient_id', (int) $user->id);
                    })
                    ->count();
            });

            $pendingAppointments = $this->safeCount(function () {
                return IntakeRequest::query()
                    ->where('status', 'pending')
                    ->count();
            });

            $newReferrals = $this->safeCount(function () {
                return Referral::query()
                    ->where('status', 'pending')
                    ->count();
            });
        } elseif ($this->isReferralUser($user)) {
            // Referral users: how many of THEIR referrals are still pending
            $newReferrals = $this->safeCount(function () use ($user) {
                return Referral::query()
                    ->where('requested_by_id', (int) $user->id)
                    ->where('status', 'pending')
                    ->count();
            });

            // If you later implement referral-user messaging + read flags, update here.
            $unreadMessages = 0;
            $pendingAppointments = 0;
        } else {
            // Student/Guest: unread messages for their thread
            $unreadMessages = $this->safeCount(function () use ($user) {
                return Message::query()
                    ->where('user_id', (int) $user->id)
                    ->where('is_read', false)
                    ->count();
            });

            $pendingAppointments = 0;
            $newReferrals = 0;
        }

        return response()->json([
            'message' => 'Fetched notification counts.',

            // ✅ top-level keys (frontend badge mapping)
            'unread_messages' => $unreadMessages,
            'pending_appointments' => $pendingAppointments,
            'new_referrals' => $newReferrals,

            // ✅ keep nested counts too (compat)
            'counts' => [
                'unread_messages' => $unreadMessages,
                'pending_appointments' => $pendingAppointments,
                'new_referrals' => $newReferrals,
            ],
        ]);
    }
}