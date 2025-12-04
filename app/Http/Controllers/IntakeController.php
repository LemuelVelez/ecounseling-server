<?php

namespace App\Http\Controllers;

use App\Models\IntakeAssessment;
use App\Models\IntakeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntakeController extends Controller
{
    /**
     * Store a new counseling intake request for the authenticated student.
     *
     * This now handles only the main concern & preferred schedule (Step 4).
     *
     * Called from the React page:
     *   POST /student/intake
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $data = $request->validate([
            // Core scheduling + description
            'concern_type'   => ['required', 'string', 'max:255'],
            'urgency'        => ['required', 'string', 'in:low,medium,high'],
            'preferred_date' => ['required', 'date'],
            'preferred_time' => ['required', 'string', 'max:50'],
            'details'        => ['required', 'string'],
        ]);

        $intake = new IntakeRequest();
        $intake->user_id        = $user->id;

        // Core fields
        $intake->concern_type   = $data['concern_type'];
        $intake->urgency        = $data['urgency'];
        $intake->preferred_date = $data['preferred_date'];
        $intake->preferred_time = $data['preferred_time'];
        $intake->details        = $data['details'];
        $intake->status         = 'pending';

        $intake->save();

        return response()->json([
            'message' => 'Your counseling request has been submitted.',
            'intake'  => $intake,
        ], 201);
    }

    /**
     * Store a new assessment record (Steps 1–3) for the authenticated student.
     *
     * This captures consent, demographic snapshot, and general mental health
     * questionnaire answers in a separate table.
     *
     * Called from the React page:
     *   POST /student/intake/assessment
     */
    public function storeAssessment(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $data = $request->validate([
            // Consent (mirrors the checkbox in the React form)
            'consent'                => ['required', 'boolean'],

            // Demographic snapshot (self-reported for this assessment)
            'student_name'           => ['nullable', 'string', 'max:255'],
            'age'                    => ['nullable', 'integer', 'min:10', 'max:120'],
            'gender'                 => ['nullable', 'string', 'max:50'],
            'occupation'             => ['nullable', 'string', 'max:255'],
            'living_situation'       => ['nullable', 'string', 'max:50'],
            'living_situation_other' => ['nullable', 'string', 'max:255'],

            // Mental health questionnaire (each field uses enum codes)
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

        // Consent & demographics
        $assessment->consent                = (bool) ($data['consent'] ?? false);
        $assessment->student_name           = $data['student_name'] ?? null;
        $assessment->age                    = $data['age'] ?? null;
        $assessment->gender                 = $data['gender'] ?? null;
        $assessment->occupation             = $data['occupation'] ?? null;
        $assessment->living_situation       = $data['living_situation'] ?? null;
        $assessment->living_situation_other = $data['living_situation_other'] ?? null;

        // MH questionnaire
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

    /**
     * List all assessment records (Steps 1–3) for the authenticated student.
     *
     * Called from the React Evaluation page:
     *   GET /student/intake/assessments
     */
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

    /**
     * List all counseling-related intake requests (appointments) for
     * the authenticated student.
     *
     * Called from the React Evaluation page:
     *   GET /student/appointments
     */
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

    /**
     * Update the details of a counseling appointment for the authenticated student.
     *
     * This is used to fix typos in the `details` field.
     *
     * Called from the React page:
     *   PUT /student/appointments/{intake}
     */
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

        // Disallow edits for already scheduled or closed requests
        if (in_array($intake->status, ['scheduled', 'closed'], true)) {
            return response()->json([
                'message' => 'You can no longer edit this request because it has already been scheduled or closed.',
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
}