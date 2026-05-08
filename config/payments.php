<?php

use App\Modules\Payments\Services\Providers\MidtransPaymentProvider;
use App\Modules\Payments\Services\Providers\XenditPaymentProvider;

return [
    'default_provider' => env('PAYMENT_DEFAULT_PROVIDER', 'midtrans'),
    'webhook' => [
        'max_event_age_seconds' => (int) env('PAYMENT_WEBHOOK_MAX_EVENT_AGE_SECONDS', 900),
    ],
    'recovery' => [
        'backoff_base_seconds' => (int) env('PAYMENT_RECOVERY_BACKOFF_BASE_SECONDS', 15),
        'backoff_max_seconds' => (int) env('PAYMENT_RECOVERY_BACKOFF_MAX_SECONDS', 600),
    ],
    'providers' => [
        'midtrans' => [
            'class' => MidtransPaymentProvider::class,
            'server_key' => env('MIDTRANS_SERVER_KEY', ''),
            'client_key' => env('MIDTRANS_CLIENT_KEY', ''),
            'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
            'snap_url' => env('MIDTRANS_SNAP_URL', 'https://app.sandbox.midtrans.com/snap/v2/snap'),
            'webhook_secret' => env('MIDTRANS_WEBHOOK_SECRET', env('MIDTRANS_SERVER_KEY', '')),
        ],
        'xendit' => [
            'class' => XenditPaymentProvider::class,
            'secret_key' => env('XENDIT_SECRET_KEY', ''),
            'webhook_token' => env('XENDIT_WEBHOOK_TOKEN', ''),
        ],
        'manual' => [
            'class' => MidtransPaymentProvider::class,
            'server_key' => env('MIDTRANS_SERVER_KEY', ''),
            'client_key' => env('MIDTRANS_CLIENT_KEY', ''),
            'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
            'snap_url' => env('MIDTRANS_SNAP_URL', 'https://app.sandbox.midtrans.com/snap/v2/snap'),
            'webhook_secret' => env('MIDTRANS_WEBHOOK_SECRET', env('MIDTRANS_SERVER_KEY', '')),
        ],
    ],
];
