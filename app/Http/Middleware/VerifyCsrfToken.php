<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * NOTE (Laravel 11/12+):
     * ----------------------
     * By default, this middleware is NOT used in the middleware stack.
     * CSRF configuration is done via $middleware->validateCsrfTokens()
     * in bootstrap/app.php.
     *
     * You can safely leave this file here, but changing $except will NOT
     * affect CSRF unless you explicitly register this middleware yourself.
     */
    protected $except = [
        //
    ];
}