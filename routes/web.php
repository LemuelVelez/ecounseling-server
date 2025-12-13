<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\StudentMessageController;
use App\Http\Controllers\StudentProfileController;
use App\Http\Controllers\Admin\AdminRoleController;
use App\Http\Controllers\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| JSON auth API for the React frontend
|--------------------------------------------------------------------------
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

    Route::post('password/forgot', [AuthController::class, 'forgotPassword'])
        ->name('auth.password.forgot');

    Route::post('password/reset', [AuthController::class, 'resetPassword'])
        ->name('auth.password.reset');
});

/*
|--------------------------------------------------------------------------
| Student counseling intake, evaluation & messages routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->prefix('student')->group(function () {
    Route::post('intake/assessment', [IntakeController::class, 'storeAssessment'])
        ->name('student.intake.assessment.store');

    Route::get('intake/assessments', [IntakeController::class, 'assessments'])
        ->name('student.intake.assessments.index');

    Route::post('intake', [IntakeController::class, 'store'])
        ->name('student.intake.store');

    Route::get('appointments', [IntakeController::class, 'appointments'])
        ->name('student.appointments.index');

    Route::put('appointments/{intake}', [IntakeController::class, 'update'])
        ->name('student.appointments.update');

    Route::get('messages', [StudentMessageController::class, 'index'])
        ->name('student.messages.index');

    Route::post('messages', [StudentMessageController::class, 'store'])
        ->name('student.messages.store');

    Route::post('messages/mark-as-read', [StudentMessageController::class, 'markAsRead'])
        ->name('student.messages.markAsRead');

    Route::post('profile/avatar', [StudentProfileController::class, 'updateAvatar'])
        ->name('student.profile.avatar');
});

/*
|--------------------------------------------------------------------------
| Counselor intake review routes (React counselor dashboard)
|--------------------------------------------------------------------------
| React calls:
|   GET   /counselor/intake/requests
|   GET   /counselor/intake/assessments
|   PATCH /counselor/appointments/{intake}          ✅ added (schedule/status updates)
|   PATCH /counselor/intake/requests/{intake}       ✅ added (compat/fallback)
*/

Route::middleware('auth')->prefix('counselor')->group(function () {
    Route::get('intake/requests', [IntakeController::class, 'counselorRequests'])
        ->name('counselor.intake.requests.index');

    Route::get('intake/assessments', [IntakeController::class, 'counselorAssessments'])
        ->name('counselor.intake.assessments.index');

    // ✅ Counselor updates: schedule + status
    Route::patch('appointments/{intake}', [IntakeController::class, 'counselorUpdateAppointment'])
        ->name('counselor.appointments.update');

    // ✅ Backwards/compat route used by frontend fallback
    Route::patch('intake/requests/{intake}', [IntakeController::class, 'counselorUpdateAppointment'])
        ->name('counselor.intake.requests.update');
});

/*
|--------------------------------------------------------------------------
| Admin routes (for the React admin dashboard)
|--------------------------------------------------------------------------
| React calls:
|   GET   /admin/roles
|   GET   /admin/users
|   POST  /admin/users
|   PATCH /admin/users/{user}/role
*/

Route::middleware('auth')->prefix('admin')->group(function () {
    Route::get('roles', [AdminRoleController::class, 'index'])
        ->name('admin.roles.index');

    Route::get('users', [AdminUserController::class, 'index'])
        ->name('admin.users.index');

    Route::post('users', [AdminUserController::class, 'store'])
        ->name('admin.users.store');

    Route::patch('users/{user}/role', [AdminUserController::class, 'updateRole'])
        ->name('admin.users.role.update');
});