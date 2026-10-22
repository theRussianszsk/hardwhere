<?php

return [
    'tokens' => [
        // Sanctum tokens are device-scoped and expire server-side. There is no refresh token: POST /auth/renew rotates a still-valid token, an expired one needs credentials again.
        'vendor_ttl_minutes' => (int) env('VENDOR_TOKEN_TTL_MINUTES', 60 * 24),
        'customer_ttl_minutes' => (int) env('CUSTOMER_TOKEN_TTL_MINUTES', 60 * 24 * 30),
    ],

    'ai' => [
        // "mock" = deterministic, clearly labelled demo provider. "gemma" = Gemma 4 through the Gemini API, "openrouter" = any vision model via OpenRouter.
        'provider' => env('AI_PROVIDER', 'mock'),
        'gemma' => [
            'base_url' => env('GEMMA_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'api_key' => env('GEMMA_API_KEY'),
            'model' => env('GEMMA_MODEL', 'gemma-4-26b-a4b-it'),
            'timeout' => (int) env('GEMMA_TIMEOUT', 45),
        ],
        'openrouter' => [
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'api_key' => env('OPENROUTER_API_KEY', env('OPEN_ROUTER_API')),
            'model' => env('OPENROUTER_MODEL', 'deepseek/deepseek-v4.1-flash-1017'),
            'timeout' => (int) env('OPENROUTER_TIMEOUT', 60),
        ],
        'max_image_kb' => (int) env('AI_MAX_IMAGE_KB', 8192),
        'max_image_edge' => 1600,
        'uploads_disk' => env('AI_UPLOADS_DISK', 'local'),
    ],

    'sync' => [
        // Hides rows from transactions that are still open so a cursor can never skip a late commit. Disable only inside wrapped test transactions.
        'xmin_horizon' => (bool) env('SYNC_XMIN_HORIZON', true),
        'page_size' => 200,
    ],

    'marketplace' => [
        'low_stock_threshold' => 5,
    ],
];
