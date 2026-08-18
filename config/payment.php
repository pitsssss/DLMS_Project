<?php

return [
    'provider' => env('PAYMENT_PROVIDER', 'mock'),

    // Application fees and payments use USD. Comparisons are case-normalized; no conversion.
    'application_currency' => 'USD',

    // Authoritative persisted currency for newly created fines (machine code, uppercase).
    // Clients never choose fine currency; electronic fine payment will copy Fine.currency → Payment.currency.
    'fine_currency' => strtoupper((string) env('FINE_CURRENCY', 'USD')),

    'stripe' => [
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Must exactly match Fee/Payment currency when Stripe is enabled. No silent conversion.
        'currency' => strtolower((string) env('STRIPE_CURRENCY', 'usd')),
        'success_url' => env('STRIPE_SUCCESS_URL'),
        'cancel_url' => env('STRIPE_CANCEL_URL'),
    ],

    'reconciliation' => [
        'stale_pending_minutes' => (int) env('PAYMENT_STALE_PENDING_MINUTES', 60),
        'batch_size' => (int) env('PAYMENT_RECONCILE_BATCH_SIZE', 50),
    ],
];
