<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminUserController extends Controller
{
    /**
     * GET /admin/users
     * List users for the admin UI.
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'gender', 'avatar_url', 'account_type', 'created_at'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'users' => $users,
        ]);
    }

    /**
     * Treat dean/registrar/program chair as ONE: referral_user.
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

    /**
     * Force storage role for referral bucket.
     */
    private function normalizeRoleForStorage(string $role): string
    {
        $role = trim((string) $role);
        if ($role === '') return $role;

        if ($this->isReferralRole($role)) {
            return 'referral_user';
        }

        return $role;
    }

    /**
     * ✅ Account type is ONLY student|guest (per DB constraint).
     * Referral is a ROLE only, so it must NEVER become account_type.
     */
    private function accountTypeForRole(string $role): string
    {
        $r = strtolower(trim((string) $role));

        if (str_contains($r, 'student')) {
            return 'student';
        }

        // everything else (admin/counselor/guest/referral_user/etc) => guest
        return 'guest';
    }

    private function userPayload(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'role', 'gender', 'avatar_url', 'account_type', 'created_at']);
    }

    /**
     * POST /admin/users
     * Create a user for the admin UI.
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => ['required', 'email', 'max:255', 'unique:users,email'],
            'role'                  => ['required', 'string', 'max:50'],
            'gender'                => ['nullable', 'string', 'max:50'],
            'password'              => ['required', 'string', Password::min(8), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $role = $this->normalizeRoleForStorage((string) $data['role']);

        $user = new User();
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = $role;
        $user->gender = $data['gender'] ?? null;

        // ✅ account_type stays ONLY student|guest
        $user->account_type = $this->accountTypeForRole($role);

        // Will be hashed automatically by the User model "password" cast
        $user->password = $data['password'];

        $user->save();

        return response()->json([
            'message' => 'User created successfully.',
            'user'    => $this->userPayload($user),
        ], 201);
    }

    /**
     * PATCH /admin/users/{user}
     * Update user fields (CRUD: Update).
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'email'                 => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'role'                  => ['required', 'string', 'max:50'],
            'gender'                => ['nullable', 'string', 'max:50'],
            'password'              => ['nullable', 'string', Password::min(8), 'confirmed'],
            'password_confirmation' => ['nullable', 'string'],
        ]);

        $role = $this->normalizeRoleForStorage((string) $data['role']);

        $user->name = (string) $data['name'];
        $user->email = (string) $data['email'];
        $user->role = $role;

        // allow null
        $user->gender = $data['gender'] ?? null;

        // ✅ IMPORTANT: DO NOT change account_type when updating role/info.
        // account_type is student|guest only, and referral_user is ROLE only.

        // Only update password if provided
        if (!empty($data['password'])) {
            $user->password = (string) $data['password'];
        }

        $user->save();

        return response()->json([
            'message' => 'User updated successfully.',
            'user'    => $this->userPayload($user),
        ]);
    }

    /**
     * PATCH /admin/users/{user}/role
     * Update a user's role.
     */
    public function updateRole(Request $request, User $user): JsonResponse
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'role' => ['required', 'string', 'max:50'],
        ]);

        $role = $this->normalizeRoleForStorage((string) $data['role']);

        $user->role = $role;

        // ✅ IMPORTANT: DO NOT change account_type here.
        // account_type constraint only allows student|guest.

        $user->save();

        return response()->json([
            'message' => 'Role updated successfully.',
            'user'    => $this->userPayload($user),
        ]);
    }

    /**
     * DELETE /admin/users/{user}
     * Delete a user (CRUD: Delete).
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->requireAdmin($request);

        $authUser = $request->user();
        if ($authUser && (string) $authUser->id === (string) $user->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    protected function requireAdmin(Request $request): void
    {
        $authUser = $request->user();

        if (!$authUser) {
            abort(401, 'Unauthenticated.');
        }

        $role = strtolower(trim((string) ($authUser->role ?? '')));

        if (!str_contains($role, 'admin')) {
            abort(403, 'Forbidden.');
        }
    }
}