<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Passport Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Passport will use when
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    |
    */

    'guard' => 'web',

    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Encryption Keys
    |--------------------------------------------------------------------------
    |
    | Passport uses encryption keys while generating secure access tokens for
    | your application. By default, the keys are stored as local files but
    | can be set via environment variables when that is more convenient.
    |
    */

    'private_key' => env('PASSPORT_PRIVATE_KEY'),

    'public_key' => env('PASSPORT_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Passport Database Connection
    |--------------------------------------------------------------------------
    |
    | By default, Passport's models will utilize your application's default
    | database connection. If you wish to use a different connection you
    | may specify the configured name of the database connection here.
    |
    */

    'connection' => env('PASSPORT_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Personal Access Token Lifetime (minutes)
    |--------------------------------------------------------------------------
    |
    | Staff ERP/POS bearer tokens (Passport personal access tokens). Defaults to
    | 24 hours. `SANCTUM_EXPIRATION_MINUTES` is accepted as a legacy alias.
    |
    */

    'personal_access_token_expire_minutes' => (int) env(
        'PASSPORT_PERSONAL_ACCESS_TOKEN_EXPIRE_MINUTES',
        env('SANCTUM_EXPIRATION_MINUTES', 1440),
    ),

];
