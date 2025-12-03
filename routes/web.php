<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IntakeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| JSON auth API for the React frontend
|--------------------------------------------------------------------------
|
| These endpoints are called from:
|   src/api/auth/route.ts
|
| Paths:
|   POST /auth/login                     -> loginApi
|   POST /auth/register                  -> registerApi
|   POST /auth/logout                    -> logoutApi
|   GET  /auth/me                        -> meApi
|   POST /auth/email/resend-verification -> resendVerification
|   GET  /auth/email/verify/{token}      -> verifyEmail
|
*/

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('me', [AuthController::class, 'me'])->name('auth.me');

    Route::post('email/resend-verification', [AuthController::class, 'resendVerification'])
        ->name('auth.email.resend');

    Route::get('email/verify/{token}', [AuthController::class, 'verifyEmail'])
        ->name('auth.email.verify');
});

/*
|--------------------------------------------------------------------------
| Student counseling intake routes
|--------------------------------------------------------------------------
|
| These endpoints are called from:
|   src/pages/dashboard/student/intake.tsx
|   src/pages/dashboard/student/appointments.tsx
|
| Paths:
|   POST /student/intake                 -> store a new counseling request
|   POST /student/intake/assessment      -> store a new assessment record
|   GET  /student/appointments           -> list counseling requests for the logged-in student
|   PUT  /student/appointments/{intake}  -> update details for a specific request
|
*/

Route::middleware('auth')->prefix('student')->group(function () {
    // New route: store Steps 1–3 (assessment) in its own table.
    Route::post('intake/assessment', [IntakeController::class, 'storeAssessment'])
        ->name('student.intake.assessment.store');

    // Main counseling request (Step 4 – concern & preferred schedule).
    Route::post('intake', [IntakeController::class, 'store'])
        ->name('student.intake.store');

    // List all counseling-related appointments/requests for the authenticated student.
    Route::get('appointments', [IntakeController::class, 'appointments'])
        ->name('student.appointments.index');

    // Update details of a single appointment (for fixing typos, etc.).
    Route::put('appointments/{intake}', [IntakeController::class, 'update'])
        ->name('student.appointments.update');
});