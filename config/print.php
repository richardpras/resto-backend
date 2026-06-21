<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Category To Station Mapping
    |--------------------------------------------------------------------------
    |
    | Optional additive mapping used by printer routing resolution to infer
    | station from menu category before station/default route fallback.
    |
    */
    'category_station_map' => [],

    'category_master' => [
        'enabled' => filter_var(env('MENU_CATEGORY_MASTER_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

    'category_mapping' => [
        'enabled' => filter_var(env('PRINT_CATEGORY_MAPPING_ENABLED', true), FILTER_VALIDATE_BOOL),
    ],

    'legacy_routing' => [
        'enabled' => filter_var(env('PRINT_LEGACY_ROUTING_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Print Dispatch Mode
    |--------------------------------------------------------------------------
    |
    | queue_worker       — enqueue ProcessPrintJob (default; requires queue:work)
    | sync_dispatch      — process print job inline after enqueue (shared hosting)
    | scheduled_dispatch — cron-driven via print:process-pending (shared hosting)
    |
    */
    'dispatch' => [
        'mode' => env('PRINT_DISPATCH_MODE', 'queue_worker'),
        'scheduled_batch_limit' => (int) env('PRINT_DISPATCH_BATCH_LIMIT', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Non-LAN Print Transports (Phase 5)
    |--------------------------------------------------------------------------
    |
    | Gate USB, Bluetooth, and Windows shared printer transports on the API.
    | LAN is always enabled. Bridge runtime must also support the transport.
    |
    */
    'transport' => [
        'usb' => [
            'enabled' => filter_var(env('PRINT_TRANSPORT_USB_ENABLED', false), FILTER_VALIDATE_BOOL),
        ],
        'bluetooth' => [
            'enabled' => filter_var(env('PRINT_TRANSPORT_BLUETOOTH_ENABLED', false), FILTER_VALIDATE_BOOL),
        ],
        'shared' => [
            'enabled' => filter_var(env('PRINT_TRANSPORT_SHARED_ENABLED', false), FILTER_VALIDATE_BOOL),
        ],
    ],
];
