<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (HTTP v1)
    |--------------------------------------------------------------------------
    |
    | Server transport for citizen push delivery.
    | Credentials are Base64-encoded service-account JSON supplied via env.
    | Base64 is encoding, not encryption — treat FIREBASE_CREDENTIALS_BASE64
    | as a production secret (same class as API keys).
    |
    | Note: `php artisan config:cache` embeds resolved config values (including
    | this secret) into bootstrap/cache/config.php, consistent with other
    | Laravel service secrets (e.g. GEMINI_API_KEY). Do not commit that file.
    |
    */

    'project_id' => env('FIREBASE_PROJECT_ID'),

    'credentials_base64' => env('FIREBASE_CREDENTIALS_BASE64'),

    'oauth' => [
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'cache_key' => 'firebase.oauth.access_token.v1',
        // Refresh this many seconds before Google's reported expiry.
        'refresh_skew_seconds' => (int) env('FIREBASE_OAUTH_REFRESH_SKEW_SECONDS', 60),
    ],

    'fcm' => [
        'base_uri' => env('FIREBASE_FCM_BASE_URI', 'https://fcm.googleapis.com'),
        'connect_timeout_seconds' => (float) env('FIREBASE_FCM_CONNECT_TIMEOUT_SECONDS', 5),
        'timeout_seconds' => (float) env('FIREBASE_FCM_TIMEOUT_SECONDS', 15),
    ],

    /*
    | Optional absolute path to a CA bundle (e.g. Mozilla cacert.pem).
    | Used when php.ini curl.cainfo / openssl.cafile are empty (common on Windows).
    | Fallback file (not committed): storage/app/private/certs/cacert.pem
    */
    'http' => [
        'ca_bundle' => env('FIREBASE_CA_BUNDLE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queued push delivery (F3/F4)
    |--------------------------------------------------------------------------
    |
    | When disabled, DB notifications continue normally with no push planning.
    |
    | Timeout invariant (must hold in production):
    |   FCM HTTP timeout (15s)
    |   < job timeout (60s)
    |   < queue database retry_after (120s)
    |
    | `tries` = max actual FCM send attempts stored on push_deliveries.attempts.
    | `job_max_tries` = Laravel queue execution budget (covers release/overlap/noise).
    |
    | Docker: entrypoint.sh exec's supervisord, which starts program queue-push.
    |
    */
    'push' => [
        // filter_var: env string "false" must not become boolean true.
        'enabled' => filter_var(env('FIREBASE_PUSH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'queue' => env('FIREBASE_PUSH_QUEUE', 'push'),
        'tries' => (int) env('FIREBASE_PUSH_TRIES', 5),
        'job_max_tries' => (int) env('FIREBASE_PUSH_JOB_MAX_TRIES', 25),
        'job_timeout_seconds' => (int) env('FIREBASE_PUSH_JOB_TIMEOUT', 60),
        // Stale processing lease: reclaim only if last_attempt_at older than this.
        'processing_lease_seconds' => (int) env('FIREBASE_PUSH_PROCESSING_LEASE', 180),
        'backoff' => [60, 120, 300, 900],
        'recovery_batch_size' => (int) env('FIREBASE_PUSH_RECOVERY_BATCH', 100),
    ],

];
