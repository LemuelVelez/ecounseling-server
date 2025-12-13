<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRoleController extends Controller
{
    /**
     * GET /admin/roles
     * Returns a list of roles for the admin UI.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $fallback = ['admin', 'counselor', 'student', 'guest'];

        $fromDb = User::query()
            ->whereNotNull('role')
            ->select('role')
            ->distinct()
            ->pluck('role')
            ->map(fn ($r) => trim((string) $r))
            ->filter()
            ->values()
            ->all();

        $roles = collect(array_merge($fallback, $fromDb))
            ->map(fn ($r) => trim((string) $r))
            ->filter()
            ->unique(fn ($r) => strtolower($r))
            ->values();

        return response()->json([
            'roles' => $roles,
        ]);
    }

    protected function requireAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $role = strtolower(trim((string) ($user->role ?? '')));

        if (! str_contains($role, 'admin')) {
            abort(403, 'Forbidden.');
        }
    }
}