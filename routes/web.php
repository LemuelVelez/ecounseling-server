<?php

use App\Http\Controllers\AuthController;
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
|   POST /auth/login    -> loginApi
|   POST /auth/register -> registerApi
|   POST /auth/logout   -> logoutApi
|   GET  /auth/me       -> meApi
|
*/

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('me', [AuthController::class, 'me'])->name('auth.me');
});