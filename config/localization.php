<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Application Locale
    |--------------------------------------------------------------------------
    |
    | Product default for citizen application localization. Keep aligned with
    | APP_LOCALE / config('app.locale') (ar). Request resolution falls back here
    | when Accept-Language and users.language are unavailable or unsupported.
    |
    */

    'default' => 'ar',

    /*
    |--------------------------------------------------------------------------
    | Supported Application Locales
    |--------------------------------------------------------------------------
    |
    | Base locales only. Regional variants such as en-US / ar-SY normalize to
    | these codes during Accept-Language negotiation.
    |
    */

    'supported' => [
        'ar',
        'en',
    ],

];
