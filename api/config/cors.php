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

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    'supports_credentials' => false,

];
