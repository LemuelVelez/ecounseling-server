<?php

namespace App\Http\Controllers;

use App\Models\IntakeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntakeController extends Controller
{
    /**
     * Store a new counseling intake request for the authenticated student.
     *
     * Called from the React page:
     *   POST /student/intake
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
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
     * List all counseling-related intake requests (appointments) for
     * the authenticated student.
     *
     * Called from the React page:
     *   GET /student/appointments
     */
    public function appointments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
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

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($intake->user_id !== $user->id) {
            return response()->json([
                'message' => 'You are not allowed to edit this appointment.',
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