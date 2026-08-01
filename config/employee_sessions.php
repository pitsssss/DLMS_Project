<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active vs idle threshold
    |--------------------------------------------------------------------------
    |
    | A still-valid session is "active" when last_seen_at is within this many
    | minutes; otherwise it is "idle". This is approximate presence, not proof
    | the employee is looking at the screen.
    |
    */
    'active_threshold_minutes' => (int) env('EMPLOYEE_SESSION_ACTIVE_THRESHOLD_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Last-seen write throttling
    |--------------------------------------------------------------------------
    |
    | Persist last_seen_at / last_ip_address at most once per this interval
    | per session to avoid writing on every authenticated API request.
    |
    */
    'last_seen_write_interval_minutes' => (int) env('EMPLOYEE_SESSION_LAST_SEEN_WRITE_MINUTES', 3),

    'default_per_page' => 20,
    'max_per_page' => 100,

    /*
    |--------------------------------------------------------------------------
    | Retention (ended sessions only)
    |--------------------------------------------------------------------------
    |
    | Configurable project policy for pruning ended session history.
    | Not a legal retention assertion. Active/idle sessions are never pruned.
    |
    */
    'retention_days' => (int) env('EMPLOYEE_SESSION_RETENTION_DAYS', 180),

    'heartbeat_enabled' => (bool) env('EMPLOYEE_SESSION_HEARTBEAT_ENABLED', true),

    'heartbeat_minimum_interval_seconds' => (int) env('EMPLOYEE_SESSION_HEARTBEAT_MIN_SECONDS', 30),

    'prune_chunk_size' => 200,

    'reconcile_chunk_size' => 200,

    /*
    |--------------------------------------------------------------------------
    | Sanctum token name used for Dashboard employee logins
    |--------------------------------------------------------------------------
    */
    'token_name' => 'dashboard-token',

    'auth_driver' => 'sanctum',

    /*
    |--------------------------------------------------------------------------
    | Production notes
    |--------------------------------------------------------------------------
    |
    | - Configure trusted proxies so Request::ip() reflects the client IP
    |   behind Nginx / load balancers / ngrok (see TrustProxies / bootstrap).
    | - Sanctum expiration: when null, tokens do not expire by default;
    |   employee_sessions.expires_at mirrors the token when set.
    |
    */
];
