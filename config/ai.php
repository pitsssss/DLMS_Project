<?php

return [
    'provider' => env('AI_PROVIDER', 'gemini'),

    'enabled' => (bool) env('AI_AGENT_ENABLED', true),

    'require_confirmation' => (bool) env('AI_AGENT_REQUIRE_CONFIRMATION', true),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'temperature' => (float) env('AI_AGENT_TEMPERATURE', 0.2),
        'timeout_seconds' => (int) env('AI_AGENT_TIMEOUT_SECONDS', 30),
    ],

    'agent' => [
        'max_history_messages' => (int) env('AI_AGENT_MAX_HISTORY_MESSAGES', 10),
        'low_confidence_threshold' => (float) env('AI_AGENT_LOW_CONFIDENCE_THRESHOLD', 0.55),
    ],
];
