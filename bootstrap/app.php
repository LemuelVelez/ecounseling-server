<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * ✅ Ensure CORS headers are actually applied.
         * In Laravel 11/12, CORS is handled by middleware + config/cors.php.
         */
        $middleware->append(HandleCors::class);

        /**
         * CSRF configuration for Laravel 11/12 + React SPA.
         *
         * We keep CSRF enabled for normal web routes, but we skip CSRF
         * validation for JSON endpoints your SPA calls directly.
         */
        $middleware->validateCsrfTokens(
            except: [
                'auth/*',

                // existing
                'student/*',
                'counselor/*',
                'admin/*',

                // ✅ FIX: referral-user JSON endpoints (so POST won't 419)
                'referral-user/*',

                // ✅ conversation/thread delete endpoints used by the frontend
                'messages',
                'messages/*',
                'conversations',
                'conversations/*',

                // ✅ directory endpoints your UI calls
                'users',
                'users/*',

                'students',
                'students/*',

                'counselors',
                'counselors/*',

                'guests',
                'guests/*',

                'admins',
                'admins/*',
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();