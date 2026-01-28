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
     * Build DB-driver-safe YEAR/MONTH expressions for the given column.
     *
     * Returns:
     * - year_select: expression used in SELECT (can include casting)
     * - month_select: expression used in SELECT (can include casting)
     * - year_group: expression used in GROUP BY
     * - month_group: expression used in GROUP BY
     */
    private function resolveYearMonthSql(string $column = 'created_at'): array
    {
        $driver = DB::getDriverName();

        // NOTE: $column here is a column name, not user input.
        // Keep it simple; no quoting needed for standard columns.

        if ($driver === 'pgsql') {
            return [
                'year_select'  => "EXTRACT(YEAR FROM {$column})::int",
                'month_select' => "EXTRACT(MONTH FROM {$column})::int",
                'year_group'   => "EXTRACT(YEAR FROM {$column})",
                'month_group'  => "EXTRACT(MONTH FROM {$column})",
            ];
        }

        if ($driver === 'sqlite') {
            // SQLite stores datetimes as text; use strftime and cast to integer.
            return [
                'year_select'  => "CAST(strftime('%Y', {$column}) AS INTEGER)",
                'month_select' => "CAST(strftime('%m', {$column}) AS INTEGER)",
                'year_group'   => "CAST(strftime('%Y', {$column}) AS INTEGER)",
                'month_group'  => "CAST(strftime('%m', {$column}) AS INTEGER)",
            ];
        }

        // MySQL / MariaDB / SQL Server
        return [
            'year_select'  => "YEAR({$column})",
            'month_select' => "MONTH({$column})",
            'year_group'   => "YEAR({$column})",
            'month_group'  => "MONTH({$column})",
        ];
    }

    /**
     * GET /counselor/analytics
     *
     * Returns:
     * - this_month_count
     * - this_semester_count
     * - range { start_date, end_date }
     * - monthly_counts [{year, month, count}]
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
        $endRaw   = trim((string) $request->query('end_date', ''));

        $start = null;
        $end   = null;

        if ($startRaw !== '') {
            try {
                $start = Carbon::parse($startRaw)->startOfDay();
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Invalid start_date. Use YYYY-MM-DD.'], 422);
            }
        }

        if ($endRaw !== '') {
            try {
                $end = Carbon::parse($endRaw)->endOfDay();
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Invalid end_date. Use YYYY-MM-DD.'], 422);
            }
        }

        // default analytics window = last 12 months
        if (! $start || ! $end) {
            $end = Carbon::now()->endOfDay();
            $start = Carbon::now()->copy()->subMonths(11)->startOfMonth();
        }

        if ($start->greaterThan($end)) {
            return response()->json(['message' => 'start_date must be earlier than or equal to end_date.'], 422);
        }

        // this month
        $thisMonthStart = Carbon::now()->startOfMonth();
        $thisMonthEnd   = Carbon::now()->endOfMonth();

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
        $ym = $this->resolveYearMonthSql('created_at');

        $monthly = IntakeRequest::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("{$ym['year_select']} as year")
            ->selectRaw("{$ym['month_select']} as month")
            ->selectRaw("COUNT(*) as count")
            ->groupByRaw("{$ym['year_group']}, {$ym['month_group']}")
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->get()
            ->map(function ($r) {
                return [
                    'year'  => (int) ($r->year ?? 0),
                    'month' => (int) ($r->month ?? 0),
                    'count' => (int) ($r->count ?? 0),
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Fetched analytics.',
            'this_month_count' => $thisMonthCount,
            'this_semester_count' => $thisSemesterCount,
            'range' => [
                'start_date' => $start->toDateString(),
                'end_date'   => $end->toDateString(),
            ],
            'monthly_counts' => $monthly,
        ]);
    }
}