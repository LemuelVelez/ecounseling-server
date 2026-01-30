<?php

namespace App\Http\Controllers;

use App\Models\IntakeRequest;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        // ✅ include your actual role string too
        return str_contains($role, 'referral_user')
            || str_contains($role, 'referral user')
            || str_contains($role, 'dean')
            || str_contains($role, 'registrar')
            || str_contains($role, 'program chair')
            || str_contains($role, 'program_chair')
            || str_contains($role, 'programchair');
    }

    /**
     * ✅ Resolve authenticated user across possible guards (web/session OR api/sanctum token).
     */
    private function resolveUser(Request $request): ?User
    {
        // Default resolver (uses current selected guard if any middleware set it)
        $u = $request->user();
        if ($u instanceof User) return $u;

        // Explicit guards (only if configured)
        try {
            $u = $request->user('web');
            if ($u instanceof User) return $u;
        } catch (\Throwable $e) {}

        try {
            $u = $request->user('api');
            if ($u instanceof User) return $u;
        } catch (\Throwable $e) {}

        try {
            $u = $request->user('sanctum');
            if ($u instanceof User) return $u;
        } catch (\Throwable $e) {}

        // Auth facade fallbacks
        try {
            $u = Auth::guard('web')->user();
            if ($u instanceof User) return $u;
        } catch (\Throwable $e) {}

        try {
            $u = Auth::guard('api')->user();
            if ($u instanceof User) return $u;
        } catch (\Throwable $e) {}

        try {
            $u = Auth::guard('sanctum')->user();
            if ($u instanceof User) return $u;
        } catch (\Throwable $e) {}

        return null;
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
     * ✅ IMPORTANT:
     * - This route is NOT behind auth middleware (see routes/web.php).
     * - If unauthenticated, return 200 with zero counts (no console spam).
     */
    public function counts(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return response()->json([
                'message' => 'Fetched notification counts.',
                'unauthenticated' => true,

                'unread_messages' => 0,
                'pending_appointments' => 0,
                'new_referrals' => 0,

                'counts' => [
                    'unread_messages' => 0,
                    'pending_appointments' => 0,
                    'new_referrals' => 0,
                ],
            ], 200);
        }

        $unreadMessages = 0;
        $pendingAppointments = 0;
        $newReferrals = 0;

        if ($this->isCounselor($user)) {
            $unreadMessages = $this->safeCount(function () use ($user) {
                return MessageBadgeCountController::unreadConversationCountFor($user);
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
            $newReferrals = $this->safeCount(function () use ($user) {
                return Referral::query()
                    ->where('requested_by_id', (int) $user->id)
                    ->where('status', 'pending')
                    ->count();
            });

            $unreadMessages = 0;
            $pendingAppointments = 0;
        } else {
            $unreadMessages = $this->safeCount(function () use ($user) {
                return MessageBadgeCountController::unreadConversationCountFor($user);
            });

            $pendingAppointments = 0;
            $newReferrals = 0;
        }

        return response()->json([
            'message' => 'Fetched notification counts.',
            'unauthenticated' => false,

            'unread_messages' => $unreadMessages,
            'pending_appointments' => $pendingAppointments,
            'new_referrals' => $newReferrals,

            'counts' => [
                'unread_messages' => $unreadMessages,
                'pending_appointments' => $pendingAppointments,
                'new_referrals' => $newReferrals,
            ],
        ]);
    }
}