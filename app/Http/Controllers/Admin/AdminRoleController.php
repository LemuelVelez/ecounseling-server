<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRoleController extends Controller
{
    /**
     * dean/registrar/program chair are ONE: referral_user.
     */
    private function isReferralRole(string $role): bool
    {
        $r = strtolower(trim($role));

        return str_contains($r, 'referral')
            || str_contains($r, 'dean')
            || str_contains($r, 'registrar')
            || str_contains($r, 'program chair')
            || str_contains($r, 'program_chair')
            || str_contains($r, 'programchair');
    }

    private function normalizeRoleForList(string $role): string
    {
        $role = trim((string) $role);
        if ($role === '') return $role;

        if ($this->isReferralRole($role)) {
            return 'referral_user';
        }

        return $role;
    }

    /**
     * GET /admin/roles
     * Returns a list of roles for the admin UI.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        // ✅ include referral_user in fallback
        $fallback = ['admin', 'counselor', 'student', 'referral_user', 'guest'];

        $fromDb = User::query()
            ->whereNotNull('role')
            ->select('role')
            ->distinct()
            ->pluck('role')
            ->map(fn ($r) => $this->normalizeRoleForList((string) $r))
            ->map(fn ($r) => trim((string) $r))
            ->filter()
            ->values()
            ->all();

        $roles = collect(array_merge($fallback, $fromDb))
            ->map(fn ($r) => $this->normalizeRoleForList((string) $r))
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

        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        $role = strtolower(trim((string) ($user->role ?? '')));

        if (!str_contains($role, 'admin')) {
            abort(403, 'Forbidden.');
        }
    }
}