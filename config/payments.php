<?php

use App\Modules\Payments\Providers\XenditQrisProvider;
use App\Modules\Payments\Services\Providers\MidtransPaymentProvider;

return [
    'default_provider' => env('PAYMENT_DEFAULT_PROVIDER', 'midtrans'),
    /** When true in APP_ENV=production, boot fails if default provider credentials are missing. */
    'strict_production_boot' => (bool) env('PAYMENT_STRICT_PRODUCTION_BOOT', true),
    /**
     * Optional per-outlet default gateway when the client omits `provider`.
     * Keys are outlet ids (int or string). Example:
     * 'outlet_overrides' => [ 1 => ['default_provider' => 'xendit'] ],
     */
    'outlet_overrides' => is_array($raw = json_decode((string) env('PAYMENT_OUTLET_GATEWAY_OVERRIDES', '{}'), true))
        ? $raw
        : [],
    'webhook' => [
        'max_event_age_seconds' => (int) env('PAYMENT_WEBHOOK_MAX_EVENT_AGE_SECONDS', 900),
    ],
    'recovery' => [
        'backoff_base_seconds' => (int) env('PAYMENT_RECOVERY_BACKOFF_BASE_SECONDS', 15),
        'backoff_max_seconds' => (int) env('PAYMENT_RECOVERY_BACKOFF_MAX_SECONDS', 600),
        'stale_pending_minutes' => (int) env('PAYMENT_RECOVERY_STALE_PENDING_MINUTES', 15),
    ],
    'capabilities' => [
        'midtrans' => [
            'hosted_checkout' => true,
            'qris' => true,
            'webhooks' => true,
            'reconcile' => true,
        ],
        'xendit' => [
            'hosted_checkout' => true,
            'qris' => true,
            'direct_qris' => true,
            'webhooks' => true,
            'reconcile' => true,
            'invoice_api' => true,
        ],
        'manual' => [
            'hosted_checkout' => false,
            'qris' => true,
            'webhooks' => true,
            'reconcile' => true,
        ],
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
            'class' => XenditQrisProvider::class,
            'secret_key' => env('XENDIT_SECRET_KEY', ''),
            'webhook_token' => env('XENDIT_WEBHOOK_TOKEN', ''),
            'api_base_url' => env('XENDIT_API_BASE_URL', 'https://api.xendit.co'),
            'qris_callback_url' => env('XENDIT_QRIS_CALLBACK_URL', ''),
            'qris_expiry_minutes' => (int) env('XENDIT_QRIS_EXPIRY_MINUTES', 15),
            'invoice_duration_seconds' => (int) env('XENDIT_INVOICE_DURATION_SECONDS', 1800),
            'payer_email' => env('XENDIT_INVOICE_PAYER_EMAIL', 'checkout@invoice.local'),
            'invoice_description' => env('XENDIT_INVOICE_DESCRIPTION', 'Restaurant order payment'),
            'http_timeout_seconds' => (int) env('XENDIT_HTTP_TIMEOUT_SECONDS', 30),
            'success_redirect_url' => env('XENDIT_SUCCESS_REDIRECT_URL', ''),
            'failure_redirect_url' => env('XENDIT_FAILURE_REDIRECT_URL', ''),
            /** @var list<string>|null JSON array in env, e.g. ["QRIS","EWALLET"] — omit for Xendit dashboard defaults */
            'payment_methods' => json_decode((string) env('XENDIT_INVOICE_PAYMENT_METHODS', '[]'), true) ?: null,
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
