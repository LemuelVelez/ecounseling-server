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
     * GET /notifications/counts
     *
     * Returns notification badges:
     * - unread_messages
     * - pending_appointments (counselor)
     * - new_referrals (counselor + referral users)
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
            $unreadMessages = Message::query()
                ->where('recipient_role', 'counselor')
                ->where('counselor_is_read', false)
                ->where(function ($q) use ($user) {
                    $q->whereNull('recipient_id')
                      ->orWhere('recipient_id', (int) $user->id);
                })
                ->count();

            $pendingAppointments = IntakeRequest::query()
                ->where('status', 'pending')
                ->count();

            $newReferrals = Referral::query()
                ->where('status', 'pending')
                ->count();
        } elseif ($this->isReferralUser($user)) {
            // Referral users: show how many of THEIR referrals are still pending
            $newReferrals = Referral::query()
                ->where('requested_by_id', (int) $user->id)
                ->where('status', 'pending')
                ->count();

            // Messaging module for referral users can be added later
            $unreadMessages = 0;
            $pendingAppointments = 0;
        } else {
            // Student/Guest: unread messages for their thread
            $unreadMessages = Message::query()
                ->where('user_id', (int) $user->id)
                ->where('is_read', false)
                ->count();

            $pendingAppointments = 0;
            $newReferrals = 0;
        }

        return response()->json([
            'message' => 'Fetched notification counts.',
            'counts' => [
                'unread_messages' => $unreadMessages,
                'pending_appointments' => $pendingAppointments,
                'new_referrals' => $newReferrals,
            ],
        ]);
    }
}