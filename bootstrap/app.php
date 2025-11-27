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
         * CSRF configuration for Laravel 12.
         *
         * DISABLE_CSRF_FOR_SPA=true  -> disable CSRF everywhere (DEV ONLY)
         * DISABLE_CSRF_FOR_SPA=false -> only skip /auth/* JSON routes
         *
         * In your current .env you have:
         *     DISABLE_CSRF_FOR_SPA=true
         * so CSRF is effectively OFF for all routes while you’re developing.
         */
        $middleware->validateCsrfTokens(
            except: env('DISABLE_CSRF_FOR_SPA', false)
                ? ['*']        // dev: skip CSRF for all routes
                : ['auth/*'], // prod: protect everything except JSON /auth routes
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();