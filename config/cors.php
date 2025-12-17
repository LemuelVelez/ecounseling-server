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

    // ✅ Enable CORS on ALL routes your SPA may call (including “directory” endpoints).
    'paths' => [
        'auth/*',

        // message APIs
        'student/*',
        'counselor/*',
        'admin/*',

        // ✅ directory/list endpoints your frontend is calling
        'users',
        'users/*',

        'students',
        'students/*',

        'counselors',
        'counselors/*',

        'guests',
        'guests/*',

        // ✅ NEW: admins directory/list endpoints (fix CORS for /admins)
        'admins',
        'admins/*',

        'sanctum/csrf-cookie',
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