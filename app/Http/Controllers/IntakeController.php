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

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $data = $request->validate([
            'concern_type'    => ['required', 'string', 'max:255'],
            'urgency'         => ['required', 'string', 'in:low,medium,high'],
            'preferred_date'  => ['required', 'date'],
            'preferred_time'  => ['required', 'string', 'max:50'],
            'details'         => ['required', 'string'],
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
}