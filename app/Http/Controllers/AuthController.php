<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Register a new user and log them in.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'     => ['required', 'string', Password::min(8), 'confirmed'],
            'gender'       => ['nullable', 'string', 'max:50'],
            'account_type' => ['required', 'in:student,guest'],
            'student_id'   => ['nullable', 'string', 'max:255'],
            'year_level'   => ['nullable', 'string', 'max:255'],
            'program'      => ['nullable', 'string', 'max:255'],
            'course'       => ['nullable', 'string', 'max:255'],
        ]);

        $user = new User();
        $user->name         = $data['name'];
        $user->email        = $data['email'];
        // password will be automatically hashed by the "password" cast on the model
        $user->password     = $data['password'];
        $user->gender       = $data['gender'] ?? null;
        $user->account_type = $data['account_type'];
        $user->role         = $data['account_type'] === 'student' ? 'student' : 'guest';
        $user->student_id   = $data['student_id'] ?? null;
        $user->year_level   = $data['year_level'] ?? null;
        $user->program      = $data['program'] ?? null;
        $user->course       = $data['course'] ?? null;

        $user->save();

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'user'  => $user,
            'token' => null,
        ], 201);
    }

    /**
     * Log in an existing user by email/password.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, true)) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], 422);
        }

        $request->session()->regenerate();

        return response()->json([
            'user'  => $request->user(),
            'token' => null,
        ]);
    }

    /**
     * Return the currently authenticated user (if any).
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json([
            'user' => $user,
        ]);
    }

    /**
     * Log the user out and invalidate the session.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }
}