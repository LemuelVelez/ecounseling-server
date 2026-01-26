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
            ->select(['id', 'name', 'email', 'role', 'gender', 'avatar_url', 'account_type'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'users' => $users,
        ]);
    }

    private function isReferralRole(string $role): bool
    {
        $r = strtolower(trim($role));

        return str_contains($r, 'dean')
            || str_contains($r, 'registrar')
            || str_contains($r, 'program chair')
            || str_contains($r, 'program_chair')
            || str_contains($r, 'programchair');
    }

    private function requireInstitutionEmailIfReferralRole(string $email, string $role, ?callable $fail = null): bool
    {
        if (! $this->isReferralRole($role)) {
            return true;
        }

        // ✅ configurable official domain
        $domain = trim((string) env('INSTITUTION_EMAIL_DOMAIN', 'jrmsu.edu.ph'));
        $domain = ltrim($domain, '@');

        $emailLower = strtolower(trim($email));
        $domainLower = strtolower($domain);

        $ok = str_ends_with($emailLower, '@' . $domainLower);

        if (! $ok && $fail) {
            $fail("Referral user emails must use the official domain: @$domainLower");
        }

        return $ok;
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
            'email'                 => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
                function ($attribute, $value, $fail) use ($request) {
                    $role = (string) $request->input('role', '');
                    $this->requireInstitutionEmailIfReferralRole((string) $value, (string) $role, $fail);
                },
            ],
            'role'                  => ['required', 'string', 'max:50'],
            'gender'                => ['nullable', 'string', 'max:50'],
            'password'              => ['required', 'string', Password::min(8), 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $role = trim((string) $data['role']);
        $normalizedRole = strtolower($role);

        // ✅ Account type mapping (keeps your existing logic but adds "referral")
        $accountType = 'guest';

        if (str_contains($normalizedRole, 'student')) {
            $accountType = 'student';
        } elseif ($this->isReferralRole($role)) {
            $accountType = 'referral';
        }

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
            'user'    => $user->only(['id', 'name', 'email', 'role', 'gender', 'avatar_url', 'account_type']),
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

        // ✅ keep account_type aligned
        if (str_contains($normalizedRole, 'student')) {
            $user->account_type = 'student';
        } elseif ($this->isReferralRole($role)) {
            $user->account_type = 'referral';
        } else {
            $user->account_type = 'guest';
        }

        $user->save();

        return response()->json([
            'message' => 'Role updated successfully.',
            'user'    => $user->only(['id', 'name', 'email', 'role', 'gender', 'avatar_url', 'account_type']),
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