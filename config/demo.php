<?php

/**
 * Public demo visitor account.
 * The password is read from the environment only. Do not hardcode it in services.
 */
return [
    'visitor' => [
        'enabled' => (bool) env('DEMO_VISITOR_ENABLED', false),
        'email' => env('DEMO_VISITOR_EMAIL', 'visitor@syrtak.demo'),
        'password' => env('DEMO_VISITOR_PASSWORD'),
        'name' => env('DEMO_VISITOR_NAME', 'زائر تجريبي'),
        'phone' => env('DEMO_VISITOR_PHONE', '0900000000'),
    ],
];
