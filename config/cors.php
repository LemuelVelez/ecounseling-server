<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration allows your React SPA on http://localhost:5173
    | to call the Laravel backend on http://localhost:8000 with cookies
    | (credentials: "include").
    |
    */

    // Enable CORS on the routes your SPA actually calls.
    'paths' => [
        'auth/*',              // /auth/login, /auth/register, /auth/logout, /auth/me
        'sanctum/csrf-cookie', // if you later use Sanctum
    ],

    'allowed_methods' => ['*'],

    // IMPORTANT: when using credentials, you CANNOT use "*".
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
        'http://127.0.0.1:5173',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Required because your fetch uses `credentials: "include"`
    'supports_credentials' => true,
];