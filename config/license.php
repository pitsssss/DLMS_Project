<?php

return [
    'validity_years' => (int) env('LICENSE_VALIDITY_YEARS', 10),
    'renewal_grace_days' => (int) env('LICENSE_RENEWAL_GRACE_DAYS', 90),

    /*
    | Public frontend page encoded in printed-license QR codes.
    | The Laravel API GET /api/licenses/verify/{token} is unchanged.
    */
    'verification_public_url' => env(
        'LICENSE_VERIFICATION_PUBLIC_URL',
        'http://localhost:3000/licenses/verify'
    ),
];
