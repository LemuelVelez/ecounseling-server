<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\StudentMessageController;
use App\Http\Controllers\CounselorMessageController;
use App\Http\Controllers\MessageConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\StudentProfileController;
use App\Http\Controllers\Admin\AdminRoleController;
use App\Http\Controllers\Admin\AdminUserController;

// ✅ NEW: Admin Messages controller
use App\Http\Controllers\Admin\MessageController as AdminMessageController;

// ✅ NEW controllers
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\ManualAssessmentScoreController;
use App\Http\Controllers\CounselorStudentController;

// ✅ NEW: Referral User Messages controller
use App\Http\Controllers\ReferralUserMessageController;

use App\Http\Middleware\AuthenticateAnyGuard;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------|
| ✅ FIX: Serve public storage files (avatars) reliably
|--------------------------------------------------------------------------|
|
| If the storage symlink is missing in some environments, /storage/* will 404.
| This route serves files from the "public" disk (storage/app/public).
|
| Frontend AvatarImage typically uses:
|   /storage/avatars/xxx.jpg
*/
Route::get('storage/{path}', function (Request $request, string $path) {
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    // basic traversal guard
    if ($path === '' || str_contains($path, '..')) {
        abort(404);
    }

    $disk = Storage::disk('public');

    if (! $disk->exists($path)) {
        abort(404);
    }

    $stream = $disk->readStream($path);
    if (! $stream) {
        abort(404);
    }

    $mime = $disk->mimeType($path) ?: 'application/octet-stream';

    return response()->stream(function () use ($stream) {
        fpassthru($stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
    }, 200, [
        'Content-Type'  => $mime,
        'Cache-Control' => 'public, max-age=31536000, immutable',
    ]);
})->where('path', '.*')->name('storage.public');

/*
|--------------------------------------------------------------------------|
| JSON auth API for the React frontend
|--------------------------------------------------------------------------|
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
|--------------------------------------------------------------------------|
| ✅ Notification counts endpoint (badges)
|--------------------------------------------------------------------------|
| IMPORTANT FIX:
| - DO NOT put this behind auth middleware, because the route middleware
|   will return 401 BEFORE your controller can return 200 with zeros.
| - Controller already returns 200 with zeros if unauthenticated.
*/
Route::get('notifications/counts', [NotificationController::class, 'counts'])
    ->name('notifications.counts');

/*
|--------------------------------------------------------------------------|
| ✅ FIX: Message update/delete endpoints (for editing messages)
|--------------------------------------------------------------------------|
| Frontend calls:
| - PATCH /messages/{id}
| - PUT   /messages/{id}
| - DELETE /messages/{id}
*/
Route::middleware(AuthenticateAnyGuard::class)->match(['PATCH', 'PUT'], 'messages/{id}', [MessageController::class, 'update'])
    ->whereNumber('id')
    ->name('messages.update');

Route::middleware(AuthenticateAnyGuard::class)->delete('messages/{id}', [MessageController::class, 'destroy'])
    ->whereNumber('id')
    ->name('messages.destroy');

/*
|--------------------------------------------------------------------------|
| ✅ Conversation delete endpoints (persist delete across refresh)
|--------------------------------------------------------------------------|
|
| Frontend tries these candidates:
| - DELETE /messages/conversations/{conversationId}
| - DELETE /conversations/{conversationId}
| - DELETE /messages/thread/{conversationId}
|
| We implement all 3 and "delete" means: hide for the current user only.
*/
Route::middleware(AuthenticateAnyGuard::class)->delete('messages/conversations/{conversationId}', [MessageConversationController::class, 'destroy'])
    ->where('conversationId', '.+')
    ->name('messages.conversations.destroy');

Route::middleware(AuthenticateAnyGuard::class)->delete('messages/thread/{conversationId}', [MessageConversationController::class, 'destroy'])
    ->where('conversationId', '.+')
    ->name('messages.thread.destroy');

Route::middleware(AuthenticateAnyGuard::class)->delete('conversations/{conversationId}', [MessageConversationController::class, 'destroy'])
    ->where('conversationId', '.+')
    ->name('conversations.destroy');

/*
|--------------------------------------------------------------------------|
| Student counseling intake, evaluation & messages routes
|--------------------------------------------------------------------------|
*/
Route::middleware(AuthenticateAnyGuard::class)->prefix('student')->group(function () {
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
|--------------------------------------------------------------------------|
| Counselor intake review routes + counselor messages
|--------------------------------------------------------------------------|
*/
Route::middleware(AuthenticateAnyGuard::class)->prefix('counselor')->group(function () {
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

    /*
    |--------------------------------------------------------------------------|
    | ✅ Analytics endpoint
    |--------------------------------------------------------------------------|
    */
    Route::get('analytics', [AnalyticsController::class, 'summary'])
        ->name('counselor.analytics.summary');

    /*
    |--------------------------------------------------------------------------|
    | ✅ Referral module endpoints (counselor side)
    |--------------------------------------------------------------------------|
    */
    Route::get('referrals', [ReferralController::class, 'counselorIndex'])
        ->name('counselor.referrals.index');

    Route::get('referrals/{id}', [ReferralController::class, 'show'])
        ->whereNumber('id')
        ->name('counselor.referrals.show');

    Route::patch('referrals/{id}', [ReferralController::class, 'update'])
        ->whereNumber('id')
        ->name('counselor.referrals.update');

    /*
    |--------------------------------------------------------------------------|
    | ✅ Student profile + history (counselor view)
    |--------------------------------------------------------------------------|
    */
    Route::get('students/{id}', [CounselorStudentController::class, 'show'])
        ->whereNumber('id')
        ->name('counselor.students.show');

    Route::get('students/{id}/history', [CounselorStudentController::class, 'history'])
        ->whereNumber('id')
        ->name('counselor.students.history');

    /*
    |--------------------------------------------------------------------------|
    | ✅ Hardcopy assessment score encoding
    |--------------------------------------------------------------------------|
    */
    Route::get('case-load', [ManualAssessmentScoreController::class, 'caseLoad'])
        ->name('counselor.case_load.index');

    Route::post('manual-scores', [ManualAssessmentScoreController::class, 'store'])
        ->name('counselor.manual_scores.store');

    Route::get('manual-scores', [ManualAssessmentScoreController::class, 'index'])
        ->name('counselor.manual_scores.index');

    /*
    |--------------------------------------------------------------------------|
    | ✅ Counselor directory aliases to stop 404s from the React app
    |--------------------------------------------------------------------------|
    */
    Route::get('students', function (Request $request) {
        return directoryResponse($request, 'student');
    })->name('counselor.directory.students');

    Route::get('guests', function (Request $request) {
        return directoryResponse($request, 'guest');
    })->name('counselor.directory.guests');

    Route::get('users', function (Request $request) {
        $roles = directoryNormalizeRolesFromRequest($request);

        if (count($roles) === 0) {
            return response()->json([
                'message' => 'role or roles query param is required.',
            ], 422);
        }

        if (count($roles) === 1) {
            return directoryResponse($request, $roles[0]);
        }

        return directoryResponseMulti($request, $roles);
    })->name('counselor.directory.users');
});

/*
|--------------------------------------------------------------------------|
| ✅ Referral User endpoints (Dean/Registrar/Program Chair)
|--------------------------------------------------------------------------|
| They can create referrals + view their own referral history.
| ✅ PLUS: referral-user messages endpoints (FIX 404 + privacy)
*/
Route::middleware(AuthenticateAnyGuard::class)->prefix('referral-user')->group(function () {
    Route::post('referrals', [ReferralController::class, 'store'])
        ->name('referral_user.referrals.store');

    Route::get('referrals', [ReferralController::class, 'referralUserIndex'])
        ->name('referral_user.referrals.index');

    Route::get('referrals/{id}', [ReferralController::class, 'show'])
        ->whereNumber('id')
        ->name('referral_user.referrals.show');

    // ✅ Referral user messages
    Route::get('messages', [ReferralUserMessageController::class, 'index'])
        ->name('referral_user.messages.index');

    Route::post('messages', [ReferralUserMessageController::class, 'store'])
        ->name('referral_user.messages.store');

    Route::post('messages/mark-as-read', [ReferralUserMessageController::class, 'markAsRead'])
        ->name('referral_user.messages.markAsRead');

    Route::match(['PATCH', 'PUT'], 'messages/{id}', [ReferralUserMessageController::class, 'update'])
        ->whereNumber('id')
        ->name('referral_user.messages.update');

    Route::delete('messages/{id}', [ReferralUserMessageController::class, 'destroy'])
        ->whereNumber('id')
        ->name('referral_user.messages.destroy');
});

/*
|--------------------------------------------------------------------------|
| ✅ Directory endpoints
|--------------------------------------------------------------------------|
*/

function directoryCanListUsers(?User $actor, string $targetRole): bool
{
    if (! $actor) return false;

    $actorRole = strtolower((string) ($actor->role ?? ''));
    $isCounselor = str_contains($actorRole, 'counselor')
        || str_contains($actorRole, 'counsellor')
        || str_contains($actorRole, 'guidance');

    $isAdmin = str_contains($actorRole, 'admin');

    $target = strtolower(trim($targetRole));

    // ✅ Anyone authenticated may list counselors (needed by StudentMessages.tsx)
    if ($target === 'counselor') return true;

    // ✅ Only counselor/admin can list other roles
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

    return $query->whereRaw('1 = 0');
}

function directoryLooksLikeFilePath(string $s): bool
{
    return (bool) (
        preg_match('/\.[a-z0-9]{2,5}(\?.*)?$/i', $s) ||
        preg_match('#(^|/)(avatars|avatar|profile|profiles|images|uploads)(/|$)#i', $s)
    );
}

function directoryResolveAvatarUrl(?string $raw): ?string
{
    if ($raw == null) return null;

    $s = trim((string) $raw);
    if ($s === '') return null;

    $s = str_replace('\\', '/', $s);

    if (preg_match('#^(data:|blob:)#i', $s)) return $s;
    if (preg_match('#^https?://#i', $s)) return $s;
    if (str_starts_with($s, '//')) {
        return request()->getScheme() . ':' . $s;
    }

    $s = preg_replace('#^storage/app/public/#i', '', $s);
    $s = preg_replace('#^public/#i', '', $s);

    $normalized = ltrim($s, '/');

    $lower = strtolower($normalized);
    $alreadyStorage = str_starts_with($lower, 'storage/')
        || str_starts_with($lower, 'api/storage/');

    if (! $alreadyStorage && directoryLooksLikeFilePath($normalized)) {
        $normalized = 'storage/' . $normalized;
    }

    $finalPath = '/' . ltrim($normalized, '/');

    return url($finalPath);
}

function directoryNormalizeRolesFromRequest(Request $request): array
{
    $rolesParam = $request->query('roles', null);
    $roleParam  = $request->query('role', null);

    $roles = [];

    if (is_array($rolesParam)) {
        $roles = $rolesParam;
    } elseif (is_string($rolesParam) && trim($rolesParam) !== '') {
        $roles = preg_split('/\s*,\s*/', trim($rolesParam), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    } elseif (is_string($roleParam) && trim($roleParam) !== '') {
        $roles = [trim($roleParam)];
    }

    $map = [
        'students'    => 'student',
        'guests'      => 'guest',
        'counselors'  => 'counselor',
        'counsellors' => 'counselor',
        'guidance'    => 'counselor',
        'admins'      => 'admin',
    ];

    $allowed = ['student', 'guest', 'counselor', 'admin'];

    $norm = [];
    foreach ($roles as $r) {
        $rr = strtolower(trim((string) $r));
        if ($rr === '') continue;
        if (isset($map[$rr])) $rr = $map[$rr];
        if (! in_array($rr, $allowed, true)) continue;
        $norm[] = $rr;
    }

    return array_values(array_unique($norm));
}

function directoryResponseMulti(Request $request, array $roles)
{
    $actor = $request->user();

    if (! $actor) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    foreach ($roles as $role) {
        if (! directoryCanListUsers($actor, $role)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
    }

    $limitRaw = $request->query('limit', $request->query('per_page', 20));
    $limit = (int) $limitRaw;
    if ($limit < 1) $limit = 20;
    if ($limit > 100) $limit = 100;

    $search = (string) ($request->query('search', $request->query('q', $request->query('query', ''))));
    $search = trim($search);

    $query = User::query();

    $query->where(function ($or) use ($roles) {
        foreach ($roles as $role) {
            $or->orWhere(function ($sub) use ($role) {
                applyDirectoryRoleFilter($sub, $role);
            });
        }
    });

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
        ->get([
            'id',
            'name',
            'email',
            'role',
            'account_type',
            'avatar_url',
            'student_id',
            'year_level',
            'program',
            'course',
            'gender',
            'created_at',
        ]);

    $users->transform(function ($u) {
        $u->avatar_url = directoryResolveAvatarUrl($u->avatar_url);
        return $u;
    });

    return response()->json([
        'message' => 'Fetched users.',
        'users'   => $users,
    ]);
}

function directoryResponse(Request $request, string $role)
{
    $actor = $request->user();

    if (! $actor) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    if (! directoryCanListUsers($actor, $role)) {
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
        ->get([
            'id',
            'name',
            'email',
            'role',
            'account_type',
            'avatar_url',
            'student_id',
            'year_level',
            'program',
            'course',
            'gender',
            'created_at',
        ]);

    $users->transform(function ($u) {
        $u->avatar_url = directoryResolveAvatarUrl($u->avatar_url);
        return $u;
    });

    return response()->json([
        'message' => 'Fetched users.',
        'users'   => $users,
    ]);
}

Route::middleware(AuthenticateAnyGuard::class)->get('students', function (Request $request) {
    return directoryResponse($request, 'student');
});

Route::middleware(AuthenticateAnyGuard::class)->get('guests', function (Request $request) {
    return directoryResponse($request, 'guest');
});

Route::middleware(AuthenticateAnyGuard::class)->get('counselors', function (Request $request) {
    return directoryResponse($request, 'counselor');
});

Route::middleware(AuthenticateAnyGuard::class)->get('admins', function (Request $request) {
    return directoryResponse($request, 'admin');
});

Route::middleware(AuthenticateAnyGuard::class)->get('users', function (Request $request) {
    $roles = directoryNormalizeRolesFromRequest($request);

    if (count($roles) === 0) {
        return response()->json([
            'message' => 'role or roles query param is required.',
        ], 422);
    }

    if (count($roles) === 1) {
        return directoryResponse($request, $roles[0]);
    }

    return directoryResponseMulti($request, $roles);
});

/*
|--------------------------------------------------------------------------|
| Admin routes (for the React admin dashboard)
|--------------------------------------------------------------------------|
*/
Route::middleware(AuthenticateAnyGuard::class)->prefix('admin')->group(function () {
    Route::get('analytics', [AnalyticsController::class, 'summary'])
        ->name('admin.analytics.summary');

    Route::get('roles', [AdminRoleController::class, 'index'])
        ->name('admin.roles.index');

    Route::get('users', [AdminUserController::class, 'index'])
        ->name('admin.users.index');

    Route::post('users', [AdminUserController::class, 'store'])
        ->name('admin.users.store');

    Route::match(['PATCH', 'PUT'], 'users/{user}', [AdminUserController::class, 'update'])
        ->whereNumber('user')
        ->name('admin.users.update');

    Route::delete('users/{user}', [AdminUserController::class, 'destroy'])
        ->whereNumber('user')
        ->name('admin.users.destroy');

    Route::patch('users/{user}/role', [AdminUserController::class, 'updateRole'])
        ->whereNumber('user')
        ->name('admin.users.role.update');

    /*
    |--------------------------------------------------------------------------|
    | ✅ Admin messages endpoints
    |--------------------------------------------------------------------------|
    */
    Route::get('messages', [AdminMessageController::class, 'index'])
        ->name('admin.messages.index');

    Route::get('messages/conversations/{conversationId}', [AdminMessageController::class, 'showConversation'])
        ->where('conversationId', '.+')
        ->name('admin.messages.conversations.show');

    Route::delete('messages/conversations/{conversationId}', [AdminMessageController::class, 'destroyConversation'])
        ->where('conversationId', '.+')
        ->name('admin.messages.conversations.destroy');

    Route::match(['PATCH', 'PUT'], 'messages/{id}', [AdminMessageController::class, 'update'])
        ->whereNumber('id')
        ->name('admin.messages.update');

    Route::delete('messages/{id}', [AdminMessageController::class, 'destroy'])
        ->whereNumber('id')
        ->name('admin.messages.destroy');
});