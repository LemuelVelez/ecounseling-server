<?php

namespace App\Http\Controllers;

use App\Mail\ResetPasswordMail;
use App\Mail\VerifyEmail;
use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Register a new user and log them in.
     * Also sends an email verification link.
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

        // Send verification email using our token table + Gmail
        $verificationEmailSent = false;

        try {
            $this->sendEmailVerification($user);
            $verificationEmailSent = true;
        } catch (\Throwable $e) {
            Log::error('[auth] Failed to send verification email', [
                'user_id'   => $user->id,
                'email'     => $user->email,
                'exception' => $e,
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'user'                    => $user,
            'token'                   => null,
            'verification_email_sent' => $verificationEmailSent,
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

    /**
     * Resend the verification email for a given address.
     * Called by the React page: POST /auth/email/resend-verification
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Do not leak whether the email exists or not too aggressively.
        if (! $user) {
            return response()->json([
                'message' => 'If an account exists for this email, we have sent a verification link.',
            ]);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'This email address has already been verified. You can sign in now.',
            ]);
        }

        try {
            $this->sendEmailVerification($user);
        } catch (\Throwable $e) {
            Log::error('[auth] Failed to resend verification email', [
                'user_id'   => $user->id,
                'email'     => $user->email,
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'We could not send a verification email right now. Please try again later.',
            ], 500);
        }

        return response()->json([
            'message' => 'We have sent a new verification link to your email address.',
        ]);
    }

    /**
     * Handle a verification link click from email.
     *
     * URL pattern: GET /auth/email/verify/{token}
     * Redirects back to the React app’s /auth/callback route.
     */
    public function verifyEmail(Request $request, string $token)
    {
        $record = EmailVerificationToken::where('token', $token)->first();

        $status  = 'error';
        $message = 'This email verification link is invalid.';

        if ($record) {
            if ($record->used_at) {
                $message = 'This email verification link has already been used.';
            } elseif ($record->expires_at && $record->expires_at->isPast()) {
                $message = 'This email verification link has expired. Please request a new one.';
            } else {
                $user = $record->user;

                if (! $user) {
                    $message = 'We could not find the account associated with this verification link.';
                } else {
                    // Mark as verified if not already
                    if (! $user->email_verified_at) {
                        $user->email_verified_at = Carbon::now();
                        $user->save();
                    }

                    $record->used_at = Carbon::now();
                    $record->save();

                    // Log the user in and regenerate the session
                    Auth::login($user);
                    $request->session()->regenerate();

                    $status  = 'success';
                    $message = 'Your email has been verified. You can now use your eCounseling account.';
                }
            }
        }

        $frontendBase = rtrim(env('FRONTEND_URL', config('app.url')), '/');
        $redirectUrl  = $frontendBase . '/auth/callback'
            . '?intent=email-verification'
            . '&status=' . $status
            . '&message=' . urlencode($message);

        return redirect()->away($redirectUrl);
    }

    /**
     * Request a password reset link.
     *
     * Endpoint: POST /auth/password/forgot
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $genericMessage = 'If an account exists for this email, we have sent a password reset link.';

        $user = User::where('email', $data['email'])->first();

        // Do not loudly reveal whether the user exists or not.
        if (! $user) {
            return response()->json([
                'message' => $genericMessage,
            ]);
        }

        try {
            // Generate a random token and store a hashed version in password_reset_tokens
            $plainToken = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token'      => Hash::make($plainToken),
                    'created_at' => Carbon::now(),
                ],
            );

            $frontendBase = rtrim(env('FRONTEND_URL', config('app.url')), '/');
            $resetUrl     = $frontendBase . '/auth/reset-password'
                . '?token=' . urlencode($plainToken)
                . '&email=' . urlencode($user->email);

            // Use your custom Mailable + Blade template
            Mail::to($user->email)->send(new ResetPasswordMail($user, $resetUrl));
        } catch (\Throwable $e) {
            Log::error('[auth] Failed to send password reset email', [
                'user_id'   => $user->id,
                'email'     => $user->email,
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'We couldn\'t send a reset link right now. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => $genericMessage,
        ]);
    }

    /**
     * Reset the password using a token from the email link.
     *
     * Endpoint: POST /auth/password/reset
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $data['email'])
            ->first();

        if (! $record) {
            return response()->json([
                'message' => 'This password reset link is invalid or has expired.',
            ], 422);
        }

        // Optional: expire after 2 hours
        if (isset($record->created_at)
            && Carbon::parse($record->created_at)->addHours(2)->isPast()
        ) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

            return response()->json([
                'message' => 'This password reset link is invalid or has expired.',
            ], 422);
        }

        // Compare the plain token from the URL with the hashed token in DB
        if (! Hash::check($data['token'], $record->token)) {
            return response()->json([
                'message' => 'This password reset link is invalid or has expired.',
            ], 422);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return response()->json([
                'message' => 'We could not find an account for this email address.',
            ], 404);
        }

        // The "password" cast on the User model will hash this automatically
        $user->password = $data['password'];
        $user->save();

        // Invalidate the token so it can't be reused
        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return response()->json([
            'message' => 'Your password has been reset successfully. You can now sign in with your new password.',
        ]);
    }

    /**
     * Internal helper to create a verification token and send email.
     */
    protected function sendEmailVerification(User $user): void
    {
        // If already verified, skip
        if ($user->email_verified_at) {
            return;
        }

        // Remove any existing tokens for this user
        EmailVerificationToken::where('user_id', $user->id)->delete();

        // Generate a random 64-character token
        $plainToken = bin2hex(random_bytes(32));

        $token = EmailVerificationToken::create([
            'user_id'    => $user->id,
            'token'      => $plainToken,
            'expires_at' => Carbon::now()->addHours(24),
        ]);

        // Send the email via Gmail SMTP (configured in config/mail.php)
        Mail::to($user->email)->send(new VerifyEmail($user, $token));
    }
}