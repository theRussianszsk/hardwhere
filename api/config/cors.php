<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Bearer-token API: no cookies, so credentials stay off. Origins are the Capacitor WebViews (iOS capacitor://localhost,
    // Android https://localhost / http://localhost) plus local Vite dev servers. Extend with CORS_ALLOWED_ORIGINS in production.
    'allowed_origins' => array_values(array_filter(array_merge(
        ['capacitor://localhost', 'http://localhost', 'https://localhost', 'http://localhost:5173', 'http://localhost:5174'],
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),

    // Outside production, also accept the dev server reached by 127.0.0.1 or a private-network address (testing on a phone over Wi-Fi).
    // Production uses only the explicit list above.
    'allowed_origins_patterns' => env('APP_ENV', 'production') === 'production' ? [] : [
        '#^https?://(localhost|127\.0\.0\.1|192\.168\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    'supports_credentials' => false,

];
