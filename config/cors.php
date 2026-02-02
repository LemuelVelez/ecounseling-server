<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Your frontend is calling Laravel routes defined in routes/web.php
    | (NOT only /api/*), so make sure those paths are included here.
    |
    */

    'paths' => [
        // default
        'api/*',
        'sanctum/csrf-cookie',

        // auth
        'auth/*',

        // notifications / badges
        'notifications/*',

        // messages + conversations
        'messages/*',
        'conversations/*',

        // role modules
        'student/*',
        'counselor/*',
        'admin/*',
        'referral-user/*',

        // ✅ directory endpoints (needed so referral-user can search admins/counselors)
        'students',
        'students/*',
        'guests',
        'guests/*',
        'counselors',
        'counselors/*',
        'admins',
        'admins/*',
        'users',
        'users/*',
        'search/*',

        // ✅ aliases used by older frontend builds
        'referral-user',
        'referral-user/*',
        'referral-users',
        'referral-users/*',
        'referral_users',
        'referral_users/*',

        // public files
        'storage/*',
    ],

    'allowed_methods' => ['*'],

    // ✅ supports_credentials=true cannot be used with "*" origins
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('FRONTEND_URL', 'http://localhost:5173,http://127.0.0.1:5173'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];