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

        // ✅ FIX: allow explicit referral_user role
        if (
            $role === 'referral_user' ||
            $role === 'referral-user' ||
            $role === 'referral user' ||
            str_contains($role, 'referral_user') ||
            str_contains($role, 'referral-user')
        ) {
            return true;
        }

        // existing role variants (Dean/Registrar/Program Chair)
        return str_contains($role, 'dean')
            || str_contains($role, 'registrar')
            || str_contains($role, 'program chair')
            || str_contains($role, 'program_chair')
            || str_contains($role, 'programchair');
    }

    /**
     * ✅ Appointment columns may or may not exist depending on migrations.
     * We guard updates to avoid silent "not saved" behavior or SQL errors.
     */
    private function referralHasScheduleColumns(): bool
    {
        return Schema::hasColumn('referrals', 'scheduled_date')
            && Schema::hasColumn('referrals', 'scheduled_time');
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

        if (! in_array('id', $cols, true)) {
            array_unshift($cols, 'id');
        }

        return $cols;
    }

    private function referralWith(): array
    {
        $studentExtras = ['student_id', 'program', 'course', 'year_level'];
        $studentCols = $this->userSelectColumns($studentExtras);

        $miniCols = $this->userSelectColumns([]);

        return [
            'student:' . implode(',', $studentCols),
            'requestedBy:' . implode(',', $miniCols),
            'counselor:' . implode(',', $miniCols),
        ];
    }

    /**
     * Resolve student by:
     * 1) users.student_id (primary)
     * 2) users.id (fallback if numeric)
     */
    private function resolveStudentFromKey(string $studentKey): ?User
    {
        $studentKey = trim((string) $studentKey);

        if ($studentKey === '') {
            return null;
        }

        return User::query()
            ->where(function ($q) use ($studentKey) {
                $q->where('student_id', $studentKey);

                if (ctype_digit($studentKey)) {
                    $q->orWhere('id', (int) $studentKey);
                }
            })
            ->first();
    }

    private function isStudent(User $u): bool
    {
        $studentRole = strtolower((string) ($u->role ?? ''));
        return str_contains($studentRole, 'student');
    }

    /**
     * POST /referral-user/referrals
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
            'student_id'   => ['required', 'string', 'max:100'],
            'concern_type' => ['required', 'string', 'max:255'],
            'urgency'      => ['required', 'in:low,medium,high'],
            'details'      => ['required', 'string'],
        ]);

        $student = $this->resolveStudentFromKey((string) $data['student_id']);

        if (! $student) {
            return response()->json([
                'message' => 'Student not found. Please check the Student ID (users.student_id).',
            ], 404);
        }

        if (! $this->isStudent($student)) {
            return response()->json(['message' => 'Selected user is not a student.'], 422);
        }

        $ref = new Referral();
        // ✅ DB stores internal users.id as FK on referrals.student_id
        $ref->student_id = (int) $student->id;
        $ref->requested_by_id = (int) $actor->id;

        $ref->concern_type = $data['concern_type'];
        $ref->urgency = $data['urgency'];
        $ref->details = $data['details'];

        $ref->status = 'pending';
        $ref->handled_at = null;
        $ref->closed_at = null;

        // ✅ Appointment fields (if columns exist, ensure null defaults)
        if ($this->referralHasScheduleColumns()) {
            $ref->scheduled_date = null;
            $ref->scheduled_time = null;
        }

        $ref->save();

        $ref->load($this->referralWith());

        return response()->json([
            'message'  => 'Referral submitted.',
            'referral' => $ref,
        ], 201);
    }

    /**
     * GET /counselor/referrals
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
            return response()->json([
                'message' => 'Failed to fetch referrals.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /referral-user/referrals
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
     * GET /counselor/referrals/{id} OR /referral-user/referrals/{id}
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
     *
     * ✅ FIX: allow counselor to save scheduled_date + scheduled_time (appointment)
     * so the frontend "Set Appointment" is persisted.
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

                // ✅ Appointment fields (primary names used by the frontend)
                'scheduled_date' => ['nullable', 'date'],
                'scheduled_time' => ['nullable', 'string', 'max:50'],

                // ✅ Back-compat aliases (in case other clients send different keys)
                'appointment_date' => ['nullable', 'date'],
                'appointment_time' => ['nullable', 'string', 'max:50'],
                'schedule_date'    => ['nullable', 'date'],
                'schedule_time'    => ['nullable', 'string', 'max:50'],
                'counseling_date'  => ['nullable', 'date'],
                'counseling_time'  => ['nullable', 'string', 'max:50'],
            ]);

            /**
             * ✅ Handle counselor assignment
             */
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

            /**
             * ✅ Handle remarks (allow clearing to null)
             */
            if (array_key_exists('remarks', $data)) {
                $ref->remarks = $data['remarks'];
            }

            /**
             * ✅ Handle appointment (scheduled_date + scheduled_time)
             * - supports aliases
             * - enforces "both or none"
             * - guards for missing DB columns (prevents silent failure)
             */
            $scheduleKeys = [
                'scheduled_date',
                'scheduled_time',
                'appointment_date',
                'appointment_time',
                'schedule_date',
                'schedule_time',
                'counseling_date',
                'counseling_time',
            ];

            $hasAnyScheduleInput = false;
            foreach ($scheduleKeys as $k) {
                if (array_key_exists($k, $data)) {
                    $hasAnyScheduleInput = true;
                    break;
                }
            }

            if ($hasAnyScheduleInput) {
                if (! $this->referralHasScheduleColumns()) {
                    return response()->json([
                        'message' =>
                            'Referral appointment columns are missing. Please run the migration to add referrals.scheduled_date and referrals.scheduled_time.',
                    ], 422);
                }

                $incomingDate =
                    $data['scheduled_date']
                    ?? $data['appointment_date']
                    ?? $data['schedule_date']
                    ?? $data['counseling_date']
                    ?? null;

                $incomingTime =
                    $data['scheduled_time']
                    ?? $data['appointment_time']
                    ?? $data['schedule_time']
                    ?? $data['counseling_time']
                    ?? null;

                // enforce "both or none"
                if (($incomingDate && ! $incomingTime) || (! $incomingDate && $incomingTime)) {
                    return response()->json([
                        'message' => 'Please provide BOTH scheduled date and scheduled time.',
                    ], 422);
                }

                // set or clear
                $ref->scheduled_date = $incomingDate ?: null;
                $ref->scheduled_time = $incomingTime ?: null;

                // If appointment is set and status NOT explicitly provided, auto-mark handled.
                if ($incomingDate && $incomingTime && empty($data['status'])) {
                    $ref->status = 'handled';
                    $ref->handled_at = $ref->handled_at ?? now();
                }

                // If appointment is set and counselor_id NOT explicitly provided,
                // and there is currently no assigned counselor, assign the actor by default.
                if ($incomingDate && $incomingTime && ! array_key_exists('counselor_id', $data) && empty($ref->counselor_id)) {
                    $ref->counselor_id = (int) $actor->id;
                }
            }

            /**
             * ✅ Handle status change (kept counselor-only)
             */
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

    /**
     * ✅ PATCH/PUT /referral-user/referrals/{id}
     * Referral-user can edit ONLY their own referral, and ONLY while pending.
     * (status changes remain counselor-only)
     */
    public function referralUserUpdate(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isReferralUser($actor)) return response()->json(['message' => 'Forbidden.'], 403);

        try {
            $ref = Referral::query()
                ->where('id', (int) $id)
                ->where('requested_by_id', (int) $actor->id)
                ->first();

            if (! $ref) {
                return response()->json(['message' => 'Referral not found.'], 404);
            }

            $currentStatus = strtolower((string) ($ref->status ?? 'pending'));
            if ($currentStatus !== 'pending') {
                return response()->json([
                    'message' => 'Only pending referrals can be edited.',
                ], 422);
            }

            $data = $request->validate([
                'student_id'   => ['nullable', 'string', 'max:100'],
                'concern_type' => ['nullable', 'string', 'max:255'],
                'urgency'      => ['nullable', 'in:low,medium,high'],
                'details'      => ['nullable', 'string'],
            ]);

            $hasAny =
                array_key_exists('student_id', $data) ||
                array_key_exists('concern_type', $data) ||
                array_key_exists('urgency', $data) ||
                array_key_exists('details', $data);

            if (! $hasAny) {
                return response()->json([
                    'message' => 'No fields provided to update.',
                ], 422);
            }

            if (array_key_exists('student_id', $data) && $data['student_id'] !== null) {
                $student = $this->resolveStudentFromKey((string) $data['student_id']);

                if (! $student) {
                    return response()->json([
                        'message' => 'Student not found. Please check the Student ID (users.student_id).',
                    ], 404);
                }

                if (! $this->isStudent($student)) {
                    return response()->json(['message' => 'Selected user is not a student.'], 422);
                }

                $ref->student_id = (int) $student->id;
            }

            if (array_key_exists('concern_type', $data) && $data['concern_type'] !== null) {
                $ref->concern_type = $data['concern_type'];
            }

            if (array_key_exists('urgency', $data) && $data['urgency'] !== null) {
                $ref->urgency = $data['urgency'];
            }

            if (array_key_exists('details', $data) && $data['details'] !== null) {
                $ref->details = $data['details'];
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

    /**
     * ✅ DELETE /referral-user/referrals/{id}
     * Referral-user can delete ONLY their own referral, and ONLY while pending.
     */
    public function referralUserDestroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isReferralUser($actor)) return response()->json(['message' => 'Forbidden.'], 403);

        try {
            $ref = Referral::query()
                ->where('id', (int) $id)
                ->where('requested_by_id', (int) $actor->id)
                ->first();

            if (! $ref) {
                return response()->json(['message' => 'Referral not found.'], 404);
            }

            $currentStatus = strtolower((string) ($ref->status ?? 'pending'));
            if ($currentStatus !== 'pending') {
                return response()->json([
                    'message' => 'Only pending referrals can be deleted.',
                ], 422);
            }

            $ref->delete();

            return response()->json([
                'message' => 'Referral deleted.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to delete referral.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}