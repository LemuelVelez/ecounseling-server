<?php

namespace App\Http\Controllers;

use App\Models\IntakeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
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
     * GET /counselor/analytics
     *
     * Returns:
     * - this_month_count
     * - this_semester_count
     * - monthly_counts (grouped)
     *
     * Optional query:
     *   start_date=YYYY-MM-DD
     *   end_date=YYYY-MM-DD
     */
    public function summary(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (! $this->isCounselor($actor)) return response()->json(['message' => 'Forbidden.'], 403);

        $startRaw = trim((string) $request->query('start_date', ''));
        $endRaw = trim((string) $request->query('end_date', ''));

        $start = $startRaw !== '' ? Carbon::parse($startRaw)->startOfDay() : null;
        $end = $endRaw !== '' ? Carbon::parse($endRaw)->endOfDay() : null;

        // default analytics window = last 12 months
        if (! $start || ! $end) {
            $end = Carbon::now()->endOfDay();
            $start = Carbon::now()->copy()->subMonths(11)->startOfMonth();
        }

        // this month
        $thisMonthStart = Carbon::now()->startOfMonth();
        $thisMonthEnd = Carbon::now()->endOfMonth();

        $thisMonthCount = IntakeRequest::query()
            ->whereBetween('created_at', [$thisMonthStart, $thisMonthEnd])
            ->count();

        // this semester (simple semester: Jan-Jun / Jul-Dec)
        $m = (int) Carbon::now()->month;
        $semesterStart = $m <= 6
            ? Carbon::now()->copy()->startOfYear()
            : Carbon::now()->copy()->startOfYear()->addMonths(6);

        $semesterEnd = $m <= 6
            ? Carbon::now()->copy()->startOfYear()->addMonths(5)->endOfMonth()
            : Carbon::now()->copy()->endOfYear();

        $thisSemesterCount = IntakeRequest::query()
            ->whereBetween('created_at', [$semesterStart, $semesterEnd])
            ->count();

        // monthly grouped counts (within analytics window)
        $monthly = IntakeRequest::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('YEAR(created_at) as year')
            ->selectRaw('MONTH(created_at) as month')
            ->selectRaw('COUNT(*) as count')
            ->groupBy(DB::raw('YEAR(created_at)'), DB::raw('MONTH(created_at)'))
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->get()
            ->map(function ($r) {
                return [
                    'year' => (int) $r->year,
                    'month' => (int) $r->month,
                    'count' => (int) $r->count,
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Fetched analytics.',
            'this_month_count' => $thisMonthCount,
            'this_semester_count' => $thisSemesterCount,
            'range' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
            'monthly_counts' => $monthly,
        ]);
    }
}