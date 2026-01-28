<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReferralController extends Controller
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
     * Only select columns that actually exist in the users table to avoid SQL errors (500).
     */
    private function userSelectColumns(array $extras = []): array
    {
        $base = ['id', 'name', 'email', 'role'];

        $wanted = array_values(array_unique(array_merge($base, $extras)));

        $cols = [];
        foreach ($wanted as $c) {
            if (Schema::hasColumn('users', $c)) {
                $cols[] = $c;
            }
        }

        // Ensure id is present for relationship mapping.
        if (! in_array('id', $cols, true)) {
            array_unshift($cols, 'id');
        }

        return $cols;
    }

    private function referralWith(): array
    {
        // Student: include extra columns only if they exist.
        $studentExtras = ['student_id', 'program', 'course', 'year_level'];
        $studentCols = $this->userSelectColumns($studentExtras);

        // RequestedBy/Counselor: keep minimal.
        $miniCols = $this->userSelectColumns([]);

        return [
            'student:' . implode(',', $studentCols),
            'requestedBy:' . implode(',', $miniCols),
            'counselor:' . implode(',', $miniCols),
        ];
    }

    /**
     * POST /referral-user/referrals
     * Create referral request (Dean/Registrar/Program Chair)
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $this->isReferralUser($actor)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'student_id'   => ['required', 'integer', 'exists:users,id'],
            'concern_type' => ['required', 'string', 'max:255'],
            'urgency'      => ['required', 'in:low,medium,high'],
            'details'      => ['required', 'string'],
        ]);

        $student = User::find((int) $data['student_id']);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $studentRole = strtolower((string) ($student->role ?? ''));
        if (! str_contains($studentRole, 'student')) {
            return response()->json(['message' => 'Selected user is not a student.'], 422);
        }

        $ref = new Referral();
        $ref->student_id = (int) $student->id;
        $ref->requested_by_id = (int) $actor->id;

        $ref->concern_type = $data['concern_type'];
        $ref->urgency = $data['urgency'];
        $ref->details = $data['details'];

        $ref->status = 'pending';
        $ref->handled_at = null;
        $ref->closed_at = null;

        $ref->save();

        $ref->load($this->referralWith());

        return response()->json([
            'message'  => 'Referral submitted.',
            'referral' => $ref,
        ], 201);
    }

    /**
     * GET /counselor/referrals
     * Counselor list referrals (with pagination)
     */
    public function counselorIndex(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isCounselor($actor)) return response()->json(['message' => 'Forbidden.'], 403);

        try {
            $perPage = (int) ($request->query('per_page', 10));
            if ($perPage < 1) $perPage = 10;
            if ($perPage > 100) $perPage = 100;

            $status = trim((string) $request->query('status', ''));
            $status = $status !== '' ? strtolower($status) : null;

            $q = Referral::query()
                ->with($this->referralWith())
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            if ($status) {
                $q->where('status', $status);
            }

            $p = $q->paginate($perPage);

            return response()->json([
                'message' => 'Fetched referrals.',
                'referrals' => $p->items(),
                'meta' => [
                    'current_page' => $p->currentPage(),
                    'per_page' => $p->perPage(),
                    'total' => $p->total(),
                    'last_page' => $p->lastPage(),
                ],
            ]);
        } catch (Throwable $e) {
            // If APP_DEBUG=true you'll see the real issue in laravel.log.
            return response()->json([
                'message' => 'Failed to fetch referrals.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /referral-user/referrals
     * Referral user list only their referrals
     */
    public function referralUserIndex(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isReferralUser($actor)) return response()->json(['message' => 'Forbidden.'], 403);

        try {
            $perPage = (int) ($request->query('per_page', 10));
            if ($perPage < 1) $perPage = 10;
            if ($perPage > 100) $perPage = 100;

            $q = Referral::query()
                ->where('requested_by_id', (int) $actor->id)
                ->with($this->referralWith())
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $p = $q->paginate($perPage);

            return response()->json([
                'message' => 'Fetched your referrals.',
                'referrals' => $p->items(),
                'meta' => [
                    'current_page' => $p->currentPage(),
                    'per_page' => $p->perPage(),
                    'total' => $p->total(),
                    'last_page' => $p->lastPage(),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch your referrals.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /counselor/referrals/{id}
     * GET /referral-user/referrals/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);

        try {
            $ref = Referral::query()
                ->with($this->referralWith())
                ->find($id);

            if (! $ref) {
                return response()->json(['message' => 'Referral not found.'], 404);
            }

            $isCounselor = $this->isCounselor($actor);
            $isReferralUser = $this->isReferralUser($actor);

            if (! $isCounselor && ! $isReferralUser) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            if ($isReferralUser && (int) $ref->requested_by_id !== (int) $actor->id) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            return response()->json([
                'referral' => $ref,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch referral.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PATCH /counselor/referrals/{id}
     * Counselor updates status + optional remarks + optional counselor assignment
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isCounselor($actor)) return response()->json(['message' => 'Forbidden.'], 403);

        try {
            $ref = Referral::find($id);

            if (! $ref) {
                return response()->json(['message' => 'Referral not found.'], 404);
            }

            $data = $request->validate([
                'status'       => ['nullable', 'in:pending,handled,closed'],
                'remarks'      => ['nullable', 'string'],
                'counselor_id' => ['nullable', 'integer', 'exists:users,id'],
            ]);

            if (array_key_exists('counselor_id', $data)) {
                $counselorId = $data['counselor_id'];

                if ($counselorId === null) {
                    $ref->counselor_id = null;
                } else {
                    $counselor = User::find((int) $counselorId);
                    if (! $counselor) {
                        return response()->json(['message' => 'Counselor not found.'], 404);
                    }

                    $role = strtolower((string) ($counselor->role ?? ''));
                    if (
                        ! str_contains($role, 'counselor') &&
                        ! str_contains($role, 'counsellor') &&
                        ! str_contains($role, 'guidance')
                    ) {
                        return response()->json(['message' => 'Assigned user is not a counselor.'], 422);
                    }

                    $ref->counselor_id = (int) $counselor->id;
                }
            }

            if (array_key_exists('remarks', $data)) {
                $ref->remarks = $data['remarks'];
            }

            if (! empty($data['status'])) {
                $status = strtolower((string) $data['status']);
                $ref->status = $status;

                if ($status === 'handled') {
                    $ref->handled_at = $ref->handled_at ?? now();
                }

                if ($status === 'closed') {
                    $ref->closed_at = $ref->closed_at ?? now();
                }
            }

            $ref->save();

            $ref->load($this->referralWith());

            return response()->json([
                'message'  => 'Referral updated.',
                'referral' => $ref,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to update referral.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}