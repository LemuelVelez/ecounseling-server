<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * CSRF configuration for Laravel 11/12 + React SPA.
         *
         * We keep CSRF enabled for normal web routes, but we skip CSRF
         * validation for the JSON endpoints your SPA calls directly:
         *   - /auth/*     (login, register, logout, me, etc.)
         *   - /student/*  (e.g. /student/intake)
         *   - /admin/*    (e.g. /admin/users, /admin/roles)
         *
         * These routes are still protected by:
         *   - The session cookie (sent with credentials: "include")
         *   - The "auth" middleware (and/or your role checks) on protected routes
         */

        $middleware->validateCsrfTokens(
            except: [
                'auth/*',    // /auth/login, /auth/register, /auth/logout, /auth/me, etc.
                'student/*', // ✅ /student/intake and other student endpoints from the SPA
                'admin/*',   // ✅ /admin/users, /admin/roles, etc.
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();