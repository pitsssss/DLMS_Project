<?php

return [

    'expires_minutes' => (int) env('OTP_EXPIRES_MINUTES', 10),

    'fixed_code' => env('OTP_FIXED_CODE'),

    'channel' => env('OTP_CHANNEL', 'email'),

];
