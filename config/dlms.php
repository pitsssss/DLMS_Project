<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business / calendar timezone
    |--------------------------------------------------------------------------
    |
    | Used for Syrian business-day calculations (Overview periods, "today"
    | appointments, chart buckets). Storage timestamps use APP_TIMEZONE (UTC).
    |
    */

    'business_timezone' => env('BUSINESS_TIMEZONE', 'Asia/Damascus'),

    /*
    |--------------------------------------------------------------------------
    | Demo / QA dataset seeding
    |--------------------------------------------------------------------------
    |
    | When true, DevelopmentDemoSeeder (and guarded demo kits) may run even if
    | APP_ENV is production. Use ONLY on hosted demo/QA servers — never on a
    | real production database. Default remains false.
    |
    | filter_var: env string "false" must not become boolean true.
    |
    */

    'demo_seeding_enabled' => filter_var(env('DEMO_SEEDING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'licenses' => [
        'expiring_soon_days' => (int) env('LICENSE_EXPIRING_SOON_DAYS', 90),
        'verification_url_path' => '/api/licenses/verify',
    ],

];
