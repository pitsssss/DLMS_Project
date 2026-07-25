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

];
