<?php

return [

    'expires_minutes' => (int) env('OTP_EXPIRES_MINUTES', 10),

    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    'fixed_code' => env('OTP_FIXED_CODE'),

    'channel' => env('OTP_CHANNEL', 'email'),

];
