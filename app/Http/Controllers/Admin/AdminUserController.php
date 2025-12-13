<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->select(['id', 'name', 'email', 'role', 'gender', 'avatar_url'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'users' => $users,
        ]);
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

        $role = trim((string) $data['role']);
        $normalizedRole = strtolower($role);

        // Keep your existing "account_type" convention from register():
        // - student -> student
        // - everything else -> guest
        $accountType = str_contains($normalizedRole, 'student') ? 'student' : 'guest';

        $user = new User();
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = $role;
        $user->gender = $data['gender'] ?? null;
        $user->account_type = $accountType;

        // Will be hashed automatically by the User model "password" cast
        $user->password = $data['password'];

        $user->save();

        return response()->json([
            'message' => 'User created successfully.',
            'user'    => $user->only(['id', 'name', 'email', 'role', 'gender', 'avatar_url']),
        ], 201);
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

        $role = trim((string) $data['role']);
        $normalizedRole = strtolower($role);

        $user->role = $role;

        // Optional: keep account_type aligned
        $user->account_type = str_contains($normalizedRole, 'student') ? 'student' : 'guest';

        $user->save();

        return response()->json([
            'message' => 'Role updated successfully.',
            'user'    => $user->only(['id', 'name', 'email', 'role', 'gender', 'avatar_url']),
        ]);
    }

    protected function requireAdmin(Request $request): void
    {
        $authUser = $request->user();

        if (! $authUser) {
            abort(401, 'Unauthenticated.');
        }

        $role = strtolower(trim((string) ($authUser->role ?? '')));

        if (! str_contains($role, 'admin')) {
            abort(403, 'Forbidden.');
        }
    }
}