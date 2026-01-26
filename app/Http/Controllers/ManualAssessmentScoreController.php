<?php

namespace App\Http\Controllers;

use App\Models\IntakeRequest;
use App\Models\ManualAssessmentScore;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManualAssessmentScoreController extends Controller
{
    private function isCounselor(?User $user): bool
    {
        if (! $user) return false;

        $role = strtolower((string) ($user->role ?? ''));

        return str_contains($role, 'counselor')
            || str_contains($role, 'counsellor')
            || str_contains($role, 'guidance');
    }

    private function scoreToRating(float $score): string
    {
        // ✅ You can adjust ranges anytime
        if ($score < 50) return 'Poor';
        if ($score < 70) return 'Fair';
        if ($score < 85) return 'Good';
        return 'Very Good';
    }

    /**
     * GET /counselor/case-load
     * List students under this counselor care (based on IntakeRequest.counselor_id)
     */
    public function caseLoad(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isCounselor($actor)) return response()->json(['message' => 'Forbidden.'], 403);

        $students = User::query()
            ->whereIn('id', function ($sub) use ($actor) {
                $sub->select('user_id')
                    ->from((new IntakeRequest())->getTable())
                    ->where('counselor_id', (int) $actor->id)
                    ->whereIn('status', ['scheduled', 'completed', 'pending']);
            })
            ->orderBy('name', 'asc')
            ->get([
                'id',
                'name',
                'email',
                'role',
                'student_id',
                'year_level',
                'program',
                'course',
                'gender',
                'avatar_url',
            ]);

        return response()->json([
            'message' => 'Fetched case load.',
            'students' => $students,
        ]);
    }

    /**
     * POST /counselor/manual-scores
     * Counselor manually encodes a hardcopy score
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isCounselor($actor)) return response()->json(['message' => 'Forbidden.'], 403);

        $data = $request->validate([
            'student_id'     => ['required', 'integer', 'exists:users,id'],
            'score'          => ['required', 'numeric', 'min:0', 'max:100'],
            'assessed_date'  => ['required', 'date'],
            'remarks'        => ['nullable', 'string'],
        ]);

        $student = User::find((int) $data['student_id']);
        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        $score = (float) $data['score'];
        $rating = $this->scoreToRating($score);

        $row = new ManualAssessmentScore();
        $row->student_id = (int) $student->id;
        $row->counselor_id = (int) $actor->id;
        $row->score = $score;
        $row->rating = $rating;
        $row->assessed_date = $data['assessed_date'];
        $row->remarks = $data['remarks'] ?? null;
        $row->save();

        $row->load([
            'student:id,name,email,role,student_id,program,course,year_level',
            'counselor:id,name,email,role',
        ]);

        return response()->json([
            'message' => 'Score saved.',
            'scoreRecord' => $row,
        ], 201);
    }

    /**
     * GET /counselor/manual-scores?student_id=123
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isCounselor($actor)) return response()->json(['message' => 'Forbidden.'], 403);

        $studentId = $request->query('student_id');

        $q = ManualAssessmentScore::query()
            ->with([
                'student:id,name,email,role,student_id,program,course,year_level',
                'counselor:id,name,email,role',
            ])
            ->orderByDesc('assessed_date')
            ->orderByDesc('id');

        if ($studentId !== null && ctype_digit((string) $studentId)) {
            $q->where('student_id', (int) $studentId);
        }

        // Only show scores made by this counselor (safe default)
        $q->where('counselor_id', (int) $actor->id);

        $scores = $q->get();

        return response()->json([
            'message' => 'Fetched scores.',
            'scores' => $scores,
        ]);
    }
}