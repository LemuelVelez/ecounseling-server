<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * These are called directly by the React SPA without a CSRF token.
     */
    protected $except = [
        'auth/login',
        'auth/register',
        'auth/logout',
    ];
}