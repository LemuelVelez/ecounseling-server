<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    // ✅ Enable CORS on ALL routes your SPA may call (including referral-user).
    'paths' => [
        'auth/*',

        // message APIs
        'student/*',
        'counselor/*',
        'admin/*',

        // ✅ referral-user APIs
        'referral-user',
        'referral-user/*',

        // ✅ FIX: alias endpoints the frontend is calling
        'referral-users',
        'referral-users/*',
        'referral_users',
        'referral_users/*',

        // ✅ FIX: add search alias paths (so /search/users won't be blocked by browser)
        'search/*',

        // ✅ notifications endpoint (badges)
        'notifications',
        'notifications/*',

        // ✅ conversation/thread delete endpoints used by the frontend
        'messages',
        'messages/*',
        'conversations',
        'conversations/*',

        // ✅ directory/list endpoints your frontend is calling
        'users',
        'users/*',

        'students',
        'students/*',

        'counselors',
        'counselors/*',

        'guests',
        'guests/*',

        // ✅ admins directory/list endpoints
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