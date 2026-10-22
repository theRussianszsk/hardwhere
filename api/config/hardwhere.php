<?php

return [
    'tokens' => [
        // Sanctum tokens are device-scoped and expire server-side. There is no refresh token: POST /auth/renew rotates a still-valid token, an expired one needs credentials again.
        'vendor_ttl_minutes' => (int) env('VENDOR_TOKEN_TTL_MINUTES', 60 * 24),
        'customer_ttl_minutes' => (int) env('CUSTOMER_TOKEN_TTL_MINUTES', 60 * 24 * 30),
    ],

    'ai' => [
        // "mock" = deterministic, clearly labelled demo provider. "gemma" = Gemma 4 through the Gemini API (generateContent). "openai" = OpenAI-compatible endpoint. "openrouter" = any vision model via OpenRouter.
        'provider' => env('AI_PROVIDER', 'mock'),
        'gemma' => [
            'base_url' => env('GEMMA_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'api_key' => env('GEMMA_API_KEY'),
            'model' => env('GEMMA_MODEL', 'gemma-4-26b-a4b-it'),
            'timeout' => (int) env('GEMMA_TIMEOUT', 45),
        ],
        // "openai" = any OpenAI-compatible chat-completions endpoint with a vision model (OpenRouter, vLLM, Ollama ...).
        'openai' => [
            'base_url' => env('AI_OPENAI_BASE_URL', 'https://openrouter.ai/api/v1'),
            'api_key' => env('AI_OPENAI_API_KEY'),
            'model' => env('AI_OPENAI_MODEL'),
            'timeout' => (int) env('AI_OPENAI_TIMEOUT', 45),
            'json_mode' => (bool) env('AI_OPENAI_JSON_MODE', false),
            'max_tokens' => (int) env('AI_OPENAI_MAX_TOKENS', 400), // the JSON reply needs ~250 tokens; a cap keeps latency low
        ],
        'openrouter' => [
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'api_key' => env('OPENROUTER_API_KEY', env('OPEN_ROUTER_API')),
            'model' => env('OPENROUTER_MODEL', 'deepseek/deepseek-v4.1-flash-1017'),
            'timeout' => (int) env('OPENROUTER_TIMEOUT', 60),
        ],
        'max_image_kb' => (int) env('AI_MAX_IMAGE_KB', 8192),
        'max_image_edge' => 1600,
        'model_image_edge' => (int) env('AI_MODEL_IMAGE_EDGE', 1024), // the stored photo stays at 1600px; the model gets a smaller, faster copy
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

    'demand' => [
        // An alert fires when this many distinct searchers look for the same thing inside the window; the same subject is not re-alerted during the cooldown.
        'min_searchers' => (int) env('DEMAND_MIN_SEARCHERS', 5),
        'window_hours' => (int) env('DEMAND_WINDOW_HOURS', 24),
        'cooldown_hours' => (int) env('DEMAND_COOLDOWN_HOURS', 72),
        // A photo search counts toward a specific product only when its best catalog candidate scores at least this.
        'photo_match_min_score' => 0.75,
        'vendor_signup_url' => env('VENDOR_SIGNUP_URL', env('APP_URL', 'http://localhost').'/vendors/join'),
    ],
];
