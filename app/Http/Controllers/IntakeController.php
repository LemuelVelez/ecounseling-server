<?php

namespace App\Http\Controllers;

use App\Models\IntakeAssessment;
use App\Models\IntakeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntakeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $data = $request->validate([
            'concern_type'   => ['required', 'string', 'max:255'],
            'urgency'        => ['required', 'string', 'in:low,medium,high'],
            'preferred_date' => ['required', 'date'],
            'preferred_time' => ['required', 'string', 'max:50'],
            'details'        => ['required', 'string'],
        ]);

        $intake = new IntakeRequest();
        $intake->user_id        = $user->id;
        $intake->concern_type   = $data['concern_type'];
        $intake->urgency        = $data['urgency'];

        // Student preference
        $intake->preferred_date = $data['preferred_date'];
        $intake->preferred_time = $data['preferred_time'];

        // Counselor final schedule starts empty ✅
        $intake->scheduled_date = null;
        $intake->scheduled_time = null;

        $intake->details        = $data['details'];
        $intake->status         = 'pending';
        $intake->save();

        return response()->json([
            'message' => 'Your counseling request has been submitted.',
            'intake'  => $intake,
        ], 201);
    }

    public function storeAssessment(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $data = $request->validate([
            'consent'                => ['required', 'boolean'],

            'student_name'           => ['nullable', 'string', 'max:255'],
            'age'                    => ['nullable', 'integer', 'min:10', 'max:120'],
            'gender'                 => ['nullable', 'string', 'max:50'],
            'occupation'             => ['nullable', 'string', 'max:255'],
            'living_situation'       => ['nullable', 'string', 'max:50'],
            'living_situation_other' => ['nullable', 'string', 'max:255'],

            'mh_little_interest'  => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
            'mh_feeling_down'     => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
            'mh_sleep'            => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
            'mh_energy'           => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
            'mh_appetite'         => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
            'mh_self_esteem'      => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
            'mh_concentration'    => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
            'mh_motor'            => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
            'mh_self_harm'        => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
        ]);

        $assessment = new IntakeAssessment();
        $assessment->user_id                = $user->id;

        $assessment->consent                = (bool) ($data['consent'] ?? false);
        $assessment->student_name           = $data['student_name'] ?? null;
        $assessment->age                    = $data['age'] ?? null;
        $assessment->gender                 = $data['gender'] ?? null;
        $assessment->occupation             = $data['occupation'] ?? null;
        $assessment->living_situation       = $data['living_situation'] ?? null;
        $assessment->living_situation_other = $data['living_situation_other'] ?? null;

        $assessment->mh_little_interest = $data['mh_little_interest'] ?? null;
        $assessment->mh_feeling_down    = $data['mh_feeling_down'] ?? null;
        $assessment->mh_sleep           = $data['mh_sleep'] ?? null;
        $assessment->mh_energy          = $data['mh_energy'] ?? null;
        $assessment->mh_appetite        = $data['mh_appetite'] ?? null;
        $assessment->mh_self_esteem     = $data['mh_self_esteem'] ?? null;
        $assessment->mh_concentration   = $data['mh_concentration'] ?? null;
        $assessment->mh_motor           = $data['mh_motor'] ?? null;
        $assessment->mh_self_harm       = $data['mh_self_harm'] ?? null;

        $assessment->save();

        return response()->json([
            'message'    => 'Your assessment has been submitted.',
            'assessment' => $assessment,
        ], 201);
    }

    public function assessments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $assessments = IntakeAssessment::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'assessments' => $assessments,
        ]);
    }

    public function appointments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $appointments = IntakeRequest::query()
            ->where('user_id', $user->id)
            ->orderBy('preferred_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'appointments' => $appointments,
        ]);
    }

    public function update(Request $request, IntakeRequest $intake): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($intake->user_id !== $user->id) {
            return response()->json([
                'message' => 'You are not allowed to edit this counseling request.',
            ], 403);
        }

        $lockedStatuses = ['scheduled', 'completed', 'cancelled', 'canceled', 'closed'];
        if (in_array((string) $intake->status, $lockedStatuses, true)) {
            return response()->json([
                'message' => 'You can no longer edit this request because it has already been handled.',
            ], 422);
        }

        $data = $request->validate([
            'details' => ['required', 'string'],
        ]);

        $intake->details = $data['details'];
        $intake->save();

        return response()->json([
            'message'     => 'Your counseling request has been updated.',
            'appointment' => $intake,
        ]);
    }

    public function counselorRequests(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $requests = IntakeRequest::query()
            ->with(['user' => function ($q) {
                $q->select('id', 'name', 'email');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'requests' => $requests,
        ]);
    }

    public function counselorAssessments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $assessments = IntakeAssessment::query()
            ->with(['user' => function ($q) {
                $q->select('id', 'name', 'email');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'assessments' => $assessments,
        ]);
    }

    /**
     * Counselor assessment endpoint that tolerates "id" being either:
     * - IntakeAssessment primary key, OR
     * - user_id (fallback: return latest assessment for that user)
     *
     * Supports:
     *   GET    -> return assessment
     *   PATCH/PUT -> optional update (safe: only updates provided fields)
     *   DELETE -> delete assessment (if found)
     *
     * This fixes:
     * - 404 from route-model binding when the client sends a user_id/intake id
     * - 405 when the client sends PUT/PATCH to this URL
     */
    public function counselorAssessment(Request $request, $id): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $role = strtolower((string) ($user->role ?? ''));
        if (! str_contains($role, 'counselor') && ! str_contains($role, 'counsellor')) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $assessment = IntakeAssessment::query()->find($id);

        // Fallback: treat {id} as user_id and return latest assessment for that user.
        if (! $assessment) {
            $assessment = IntakeAssessment::query()
                ->where('user_id', $id)
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if (! $assessment) {
            return response()->json([
                'message' => 'Assessment not found.',
            ], 404);
        }

        $method = strtoupper($request->method());

        if ($method === 'DELETE') {
            $assessment->delete();

            return response()->json([
                'message' => 'Assessment deleted.',
            ]);
        }

        // Allow PUT/PATCH so client doesn't get 405.
        if ($method === 'PUT' || $method === 'PATCH') {
            $data = $request->validate([
                'consent'                => ['nullable', 'boolean'],

                'student_name'           => ['nullable', 'string', 'max:255'],
                'age'                    => ['nullable', 'integer', 'min:10', 'max:120'],
                'gender'                 => ['nullable', 'string', 'max:50'],
                'occupation'             => ['nullable', 'string', 'max:255'],
                'living_situation'       => ['nullable', 'string', 'max:50'],
                'living_situation_other' => ['nullable', 'string', 'max:255'],

                'mh_little_interest'  => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
                'mh_feeling_down'     => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
                'mh_sleep'            => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
                'mh_energy'           => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
                'mh_appetite'         => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
                'mh_self_esteem'      => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
                'mh_concentration'    => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
                'mh_motor'            => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
                'mh_self_harm'        => ['nullable', 'string', 'in:not_at_all,several_days,more_than_half,nearly_every_day'],
            ]);

            if (! empty($data)) {
                $assessment->fill($data);
                $assessment->save();
            }
        }

        $assessment->load(['user' => function ($q) {
            $q->select('id', 'name', 'email');
        }]);

        return response()->json([
            'assessment' => $assessment,
        ]);
    }

    public function counselorUpdateAppointment(Request $request, IntakeRequest $intake): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $role = strtolower((string) ($user->role ?? ''));
        if (! str_contains($role, 'counselor') && ! str_contains($role, 'counsellor')) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,scheduled,completed,cancelled,canceled,closed'],

            'scheduled_date' => ['nullable', 'date'],
            'scheduled_time' => ['nullable', 'string', 'max:50'],
            'preferred_date' => ['nullable', 'date'],
            'preferred_time' => ['nullable', 'string', 'max:50'],
        ]);

        $incomingDate = $data['scheduled_date'] ?? $data['preferred_date'] ?? null;
        $incomingTime = $data['scheduled_time'] ?? $data['preferred_time'] ?? null;

        if (($incomingDate && ! $incomingTime) || (! $incomingDate && $incomingTime)) {
            return response()->json([
                'message' => 'Please provide both date and time when setting an appointment schedule.',
            ], 422);
        }

        if ($incomingDate && $incomingTime) {
            $intake->scheduled_date = $incomingDate;
            $intake->scheduled_time = $incomingTime;

            if (empty($data['status'])) {
                $intake->status = 'scheduled';
            }
        }

        if (! empty($data['status'])) {
            $status = strtolower((string) $data['status']);
            if ($status === 'canceled') {
                $status = 'cancelled';
            }
            $intake->status = $status;
        }

        $intake->save();

        return response()->json([
            'message'     => 'Appointment updated.',
            'appointment' => $intake,
        ]);
    }

    public function counselorDeleteAppointment(Request $request, IntakeRequest $intake): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $role = strtolower((string) ($user->role ?? ''));
        if (! str_contains($role, 'counselor') && ! str_contains($role, 'counsellor')) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $intake->delete();

        return response()->json([
            'message' => 'Appointment/request deleted.',
        ]);
    }
}