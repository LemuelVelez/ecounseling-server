<?php

namespace App\Http\Controllers;

use App\Models\IntakeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CounselorStudentController extends Controller
{
    private function isCounselor(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));

        return str_contains($role, 'counselor')
            || str_contains($role, 'counsellor')
            || str_contains($role, 'guidance');
    }

    /**
     * GET /counselor/students/{id}
     * Returns basic student profile data
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isCounselor($actor)) return response()->json(['message' => 'Forbidden.'], 403);

        $student = User::query()->find($id);

        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $role = strtolower((string) ($student->role ?? ''));
        if (! str_contains($role, 'student')) {
            return response()->json(['message' => 'Selected user is not a student.'], 422);
        }

        $totalAppointments = IntakeRequest::query()
            ->where('user_id', (int) $student->id)
            ->count();

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'role' => $student->role,
                'gender' => $student->gender,
                'student_id' => $student->student_id,
                'year_level' => $student->year_level,
                'program' => $student->program,
                'course' => $student->course,
                'avatar_url' => $student->avatar_url,
                'created_at' => $student->created_at,
            ],
            'summary' => [
                'total_appointments' => $totalAppointments,
            ],
        ]);
    }

    /**
     * GET /counselor/students/{id}/history
     * Returns counseling appointment history (reasons/concerns)
     */
    public function history(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isCounselor($actor)) return response()->json(['message' => 'Forbidden.'], 403);

        $student = User::query()->find($id);

        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $role = strtolower((string) ($student->role ?? ''));
        if (! str_contains($role, 'student')) {
            return response()->json(['message' => 'Selected user is not a student.'], 422);
        }

        $history = IntakeRequest::query()
            ->where('user_id', (int) $student->id)
            ->orderByDesc('created_at')
            ->get([
                'id',
                'concern_type',
                'urgency',
                'preferred_date',
                'preferred_time',
                'scheduled_date',
                'scheduled_time',
                'status',
                'created_at',
            ]);

        return response()->json([
            'student_id' => (int) $student->id,
            'history' => $history,
        ]);
    }
}