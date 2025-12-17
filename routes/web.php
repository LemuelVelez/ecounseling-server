<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\StudentMessageController;
use App\Http\Controllers\CounselorMessageController;
use App\Http\Controllers\StudentProfileController;
use App\Http\Controllers\Admin\AdminRoleController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Models\User;
use Illuminate\Http\Request;
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

    Route::match(['GET', 'DELETE'], 'intake/assessments/{id}', [IntakeController::class, 'studentAssessment'])
        ->name('student.intake.assessments.show');

    Route::get('assessments', [IntakeController::class, 'assessments'])
        ->name('student.assessments.index');

    Route::match(['GET', 'DELETE'], 'assessments/{id}', [IntakeController::class, 'studentAssessment'])
        ->name('student.assessments.show');

    Route::post('intake', [IntakeController::class, 'store'])
        ->name('student.intake.store');

    Route::get('appointments', [IntakeController::class, 'appointments'])
        ->name('student.appointments.index');

    Route::match(['GET', 'DELETE'], 'appointments/{id}', [IntakeController::class, 'studentAppointment'])
        ->name('student.appointments.show');

    Route::match(['GET', 'DELETE'], 'intake/requests/{id}', [IntakeController::class, 'studentAppointment'])
        ->name('student.intake.requests.show');

    Route::match(['GET', 'DELETE'], 'counseling/requests/{id}', [IntakeController::class, 'studentAppointment'])
        ->name('student.counseling.requests.show');

    Route::match(['GET', 'DELETE'], 'evaluations/{id}', [IntakeController::class, 'studentAppointment'])
        ->name('student.evaluations.show');

    Route::match(['PUT', 'PATCH'], 'appointments/{intake}', [IntakeController::class, 'update'])
        ->name('student.appointments.update');

    // ✅ Student/Guest messages (student inbox thread)
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
| Counselor intake review routes + counselor messages
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->prefix('counselor')->group(function () {
    Route::get('intake/requests', [IntakeController::class, 'counselorRequests'])
        ->name('counselor.intake.requests.index');

    Route::get('intake/assessments', [IntakeController::class, 'counselorAssessments'])
        ->name('counselor.intake.assessments.index');

    Route::match(['GET', 'PATCH', 'PUT', 'DELETE'], 'intake/assessments/{id}', [IntakeController::class, 'counselorAssessment'])
        ->name('counselor.intake.assessments.show');

    Route::match(['GET', 'PATCH', 'PUT', 'DELETE'], 'assessments/{id}', [IntakeController::class, 'counselorAssessment'])
        ->name('counselor.assessments.show');

    Route::match(['GET', 'PATCH', 'PUT', 'DELETE'], 'intake/assessment/{id}', [IntakeController::class, 'counselorAssessment'])
        ->name('counselor.intake.assessment.show');

    Route::match(['PATCH', 'PUT'], 'appointments/{intake}', [IntakeController::class, 'counselorUpdateAppointment'])
        ->name('counselor.appointments.update');

    Route::match(['PATCH', 'PUT'], 'intake/requests/{intake}', [IntakeController::class, 'counselorUpdateAppointment'])
        ->name('counselor.intake.requests.update');

    Route::delete('appointments/{intake}', [IntakeController::class, 'counselorDeleteAppointment'])
        ->name('counselor.appointments.delete');

    Route::delete('intake/requests/{intake}', [IntakeController::class, 'counselorDeleteAppointment'])
        ->name('counselor.intake.requests.delete');

    // ✅ Counselor messages endpoints
    Route::get('messages', [CounselorMessageController::class, 'index'])
        ->name('counselor.messages.index');

    Route::post('messages', [CounselorMessageController::class, 'store'])
        ->name('counselor.messages.store');

    Route::post('messages/mark-as-read', [CounselorMessageController::class, 'markAsRead'])
        ->name('counselor.messages.markAsRead');
});

/*
|--------------------------------------------------------------------------
| ✅ Directory endpoints (fix 404 for /students, /guests, /counselors, /admins, /users?role=...)
|--------------------------------------------------------------------------
|
| IMPORTANT:
| - We filter by `users.role` (NOT account_type).
| - Used by counselor "New Message" recipient search.
|
| Supported query params:
| - limit | per_page (default 20, max 100)
| - search | q | query
*/

function directoryCanListUsers(?User $actor): bool
{
    if (! $actor) return false;

    $actorRole = strtolower((string) ($actor->role ?? ''));
    $isCounselor = str_contains($actorRole, 'counselor')
        || str_contains($actorRole, 'counsellor')
        || str_contains($actorRole, 'guidance');

    $isAdmin = str_contains($actorRole, 'admin');

    return $isCounselor || $isAdmin;
}

function applyDirectoryRoleFilter($query, string $role)
{
    $r = strtolower(trim($role));

    if ($r === 'student') {
        return $query->whereRaw('LOWER(role) LIKE ?', ['%student%']);
    }

    if ($r === 'guest') {
        return $query->whereRaw('LOWER(role) LIKE ?', ['%guest%']);
    }

    if ($r === 'counselor') {
        return $query->where(function ($q) {
            $q->whereRaw('LOWER(role) LIKE ?', ['%counselor%'])
              ->orWhereRaw('LOWER(role) LIKE ?', ['%counsellor%'])
              ->orWhereRaw('LOWER(role) LIKE ?', ['%guidance%']);
        });
    }

    if ($r === 'admin') {
        return $query->whereRaw('LOWER(role) LIKE ?', ['%admin%']);
    }

    // Unknown role => return none (safer than returning everyone)
    return $query->whereRaw('1 = 0');
}

function directoryResponse(Request $request, string $role)
{
    $actor = $request->user();

    if (! $actor) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    if (! directoryCanListUsers($actor)) {
        return response()->json(['message' => 'Forbidden.'], 403);
    }

    $limitRaw = $request->query('limit', $request->query('per_page', 20));
    $limit = (int) $limitRaw;
    if ($limit < 1) $limit = 20;
    if ($limit > 100) $limit = 100;

    $search = (string) ($request->query('search', $request->query('q', $request->query('query', ''))));
    $search = trim($search);

    $query = User::query();
    $query = applyDirectoryRoleFilter($query, $role);

    if ($search !== '') {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';

        $query->where(function ($q) use ($like, $search) {
            $q->where('name', 'like', $like)
              ->orWhere('email', 'like', $like);

            if (ctype_digit($search)) {
                $q->orWhere('id', (int) $search);
            }
        });
    }

    $users = $query
        ->orderBy('name', 'asc')
        ->orderBy('id', 'asc')
        ->limit($limit)
        ->get(['id', 'name', 'email', 'role', 'account_type']);

    return response()->json([
        'message' => 'Fetched users.',
        'users' => $users,
    ]);
}

Route::middleware('auth')->get('students', function (Request $request) {
    return directoryResponse($request, 'student');
});

Route::middleware('auth')->get('guests', function (Request $request) {
    return directoryResponse($request, 'guest');
});

Route::middleware('auth')->get('counselors', function (Request $request) {
    return directoryResponse($request, 'counselor');
});

Route::middleware('auth')->get('admins', function (Request $request) {
    return directoryResponse($request, 'admin');
});

/**
 * Generic endpoint used by the frontend fallback:
 * GET /users?role=student|guest|counselor|admin
 */
Route::middleware('auth')->get('users', function (Request $request) {
    $role = (string) $request->query('role', '');
    $role = trim($role);

    if ($role === '') {
        return response()->json([
            'message' => 'role query param is required.',
        ], 422);
    }

    return directoryResponse($request, $role);
});

/*
|--------------------------------------------------------------------------
| Admin routes (for the React admin dashboard)
|--------------------------------------------------------------------------
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