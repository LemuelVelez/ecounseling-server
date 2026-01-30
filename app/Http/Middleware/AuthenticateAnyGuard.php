<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAnyGuard
{
    /**
     * Try multiple guards so SPA requests authenticated via:
     * - web session cookie
     * - sanctum token
     * - api token (passport/jwt/etc)
     *
     * If any guard authenticates, we set it as the default for this request
     * via Auth::shouldUse($guard), so $request->user() resolves correctly.
     */
    public function handle(Request $request, Closure $next, ...$guards): Response
    {
        $guards = count($guards) > 0 ? $guards : ['web', 'sanctum', 'api'];

        // If a previous middleware already resolved a user, allow.
        if ($request->user()) {
            return $next($request);
        }

        foreach ($guards as $guard) {
            try {
                if (Auth::guard($guard)->check()) {
                    Auth::shouldUse($guard);
                    return $next($request);
                }
            } catch (\Throwable $e) {
                // ignore and try next guard
            }
        }

        return response()->json([
            'message' => 'Unauthenticated.',
        ], 401);
    }
}