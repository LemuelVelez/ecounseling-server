<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\StudentMessageController;
use App\Http\Controllers\StudentProfileController;
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
| Student counseling intake, evaluation & messages routes
|--------------------------------------------------------------------------
|
| These endpoints are called from:
|   src/pages/dashboard/student/intake.tsx
|   src/pages/dashboard/student/evaluation.tsx
|   src/api/intake/route.ts
|   src/pages/dashboard/student/messages.tsx
|   src/api/messages/route.ts
|
| Paths (intake / evaluation):
|   POST /student/intake                 -> store a new counseling request
|   POST /student/intake/assessment      -> store a new assessment record
|   GET  /student/intake/assessments     -> list assessment records for the logged-in student
|   GET  /student/appointments           -> list counseling requests for the logged-in student
|   PUT  /student/appointments/{intake}  -> update details for a specific request
|
| Paths (messages):
|   GET  /student/messages               -> list messages for the logged-in student
|   POST /student/messages               -> create a new student-authored message
|   POST /student/messages/mark-as-read  -> mark messages as read (by IDs or all)
|
*/

Route::middleware('auth')->prefix('student')->group(function () {
    // New route: store Steps 1–3 (assessment) in its own table.
    Route::post('intake/assessment', [IntakeController::class, 'storeAssessment'])
        ->name('student.intake.assessment.store');

    // New route: list all assessment records for the authenticated student.
    Route::get('intake/assessments', [IntakeController::class, 'assessments'])
        ->name('student.intake.assessments.index');

    // Main counseling request (Step 4 – concern & preferred schedule).
    Route::post('intake', [IntakeController::class, 'store'])
        ->name('student.intake.store');

    // List all counseling-related appointments/requests for the authenticated student.
    Route::get('appointments', [IntakeController::class, 'appointments'])
        ->name('student.appointments.index');

    // Update details of a single appointment (for fixing typos, etc.).
    Route::put('appointments/{intake}', [IntakeController::class, 'update'])
        ->name('student.appointments.update');

    // ------------------------------------------------------------------
    // Student messages (inbox-style messaging with Guidance Office)
    // ------------------------------------------------------------------

    // List messages for the logged-in student.
    Route::get('messages', [StudentMessageController::class, 'index'])
        ->name('student.messages.index');

    // Create a new student-authored message.
    Route::post('messages', [StudentMessageController::class, 'store'])
        ->name('student.messages.store');

    // Mark messages as read (by IDs or all).
    Route::post('messages/mark-as-read', [StudentMessageController::class, 'markAsRead'])
        ->name('student.messages.markAsRead');

    // ------------------------------------------------------------------
    // Student profile (avatar, etc.)
    // ------------------------------------------------------------------

    // Upload / update avatar for the authenticated student.
    Route::post('profile/avatar', [StudentProfileController::class, 'updateAvatar'])
        ->name('student.profile.avatar');
});