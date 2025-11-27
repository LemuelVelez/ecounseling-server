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
     * Build CORS headers for the current request so the React SPA
     * running on http://localhost:5173 can talk to this controller.
     */
    protected function corsHeaders(Request $request): array
    {
        $origin = $request->headers->get('Origin', '');

        $allowedOrigins = [
            // You can override this via FRONTEND_URL in .env
            config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')),
            'http://localhost:5173',
            'http://127.0.0.1:5173',
        ];

        if ($origin && in_array($origin, $allowedOrigins, true)) {
            return [
                'Access-Control-Allow-Origin'      => $origin,
                'Vary'                             => 'Origin',
                'Access-Control-Allow-Credentials' => 'true',
            ];
        }

        // If the Origin doesn't match, don't send ACAO
        return [];
    }

    /**
     * Register a new user and log them in.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'          => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'       => ['required', 'string', Password::min(8), 'confirmed'],
            'gender'         => ['nullable', 'string', 'max:50'],
            'account_type'   => ['required', 'in:student,guest'],
            'student_id'     => ['nullable', 'string', 'max:255'],
            'year_level'     => ['nullable', 'string', 'max:255'],
            'program'        => ['nullable', 'string', 'max:255'],
            'course'         => ['nullable', 'string', 'max:255'],
        ]);

        $user = new User();
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

        return response()
            ->json([
                'user'  => $user,
                'token' => null,
            ], 201)
            ->withHeaders($this->corsHeaders($request));
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
            return response()
                ->json([
                    'message' => 'Invalid email or password.',
                ], 422)
                ->withHeaders($this->corsHeaders($request));
        }

        $request->session()->regenerate();

        return response()
            ->json([
                'user'  => $request->user(),
                'token' => null,
            ])
            ->withHeaders($this->corsHeaders($request));
    }

    /**
     * Return the currently authenticated user (if any).
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()
                ->json([
                    'message' => 'Unauthenticated.',
                ], 401)
                ->withHeaders($this->corsHeaders($request));
        }

        return response()
            ->json([
                'user' => $user,
            ])
            ->withHeaders($this->corsHeaders($request));
    }

    /**
     * Log the user out and invalidate the session.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()
            ->json([
                'message' => 'Logged out.',
            ])
            ->withHeaders($this->corsHeaders($request));
    }
}