<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserDirectoryController extends Controller
{
    private function isCounselor(?User $user): bool
    {
        if (! $user) return false;
        $role = strtolower((string) ($user->role ?? ''));
        return str_contains($role, 'counselor') || str_contains($role, 'counsellor');
    }

    /**
     * GET /students
     */
    public function students(Request $request): JsonResponse
    {
        return $this->listUsers($request, 'student');
    }

    /**
     * GET /guests
     */
    public function guests(Request $request): JsonResponse
    {
        return $this->listUsers($request, 'guest');
    }

    /**
     * GET /counselors
     */
    public function counselors(Request $request): JsonResponse
    {
        return $this->listUsers($request, 'counselor');
    }

    /**
     * GET /users?role=student|guest|counselor
     */
    public function users(Request $request): JsonResponse
    {
        $role = $request->query('role');
        $role = is_string($role) && trim($role) !== '' ? strtolower(trim($role)) : null;

        return $this->listUsers($request, $role);
    }

    /**
     * IMPORTANT FIX:
     * - ROLE is the basis (NOT account_type).
     * - This prevents users like role=admin & account_type=guest from appearing as "guest".
     */
    private function listUsers(Request $request, ?string $roleFilter): JsonResponse
    {
        $authUser = $request->user();

        if (! $authUser) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Only counselors should be able to use the directory picker
        if (! $this->isCounselor($authUser)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $search =
            (string) ($request->query('search')
                ?? $request->query('q')
                ?? $request->query('query')
                ?? '');

        $search = trim($search);

        $limit = (int) ($request->query('limit') ?? $request->query('per_page') ?? 20);
        if ($limit <= 0) $limit = 20;
        if ($limit > 100) $limit = 100;

        $q = User::query()
            ->select(['id', 'name', 'email', 'role', 'student_id'])
            ->orderBy('name', 'asc')
            ->orderBy('id', 'asc');

        // ✅ ROLE FILTER (role field ONLY)
        if ($roleFilter) {
            if ($roleFilter === 'counselor') {
                $q->where(function ($w) {
                    $w->where('role', 'like', '%counselor%')
                        ->orWhere('role', 'like', '%counsellor%')
                        ->orWhere('role', 'like', '%guidance%');
                });
            } elseif ($roleFilter === 'student' || $roleFilter === 'guest') {
                $q->where('role', $roleFilter);
            } else {
                // If someone passes an unknown role, return empty rather than guessing.
                $q->whereRaw('1 = 0');
            }
        }

        // Search by name/email/student_id/id
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $w->orWhere('id', (int) $search);
                }
            });
        }

        $users = $q->limit($limit)->get()->map(function (User $u) {
            // ✅ Normalize using ROLE only
            $roleRaw = strtolower(trim((string) ($u->role ?? '')));
            $role = $roleRaw;

            if ($roleRaw !== '') {
                if (str_contains($roleRaw, 'counselor') || str_contains($roleRaw, 'counsellor') || str_contains($roleRaw, 'guidance')) {
                    $role = 'counselor';
                } elseif ($roleRaw === 'students') {
                    $role = 'student';
                } elseif ($roleRaw === 'guests') {
                    $role = 'guest';
                }
                // admin stays admin, etc.
            }

            return [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $role,
            ];
        });

        return response()->json([
            'message' => 'Fetched users.',
            'users' => $users,
        ]);
    }
}