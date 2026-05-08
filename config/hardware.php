<?php

return [
    'reconnect_gap_minutes' => (int) env('HARDWARE_RECONNECT_GAP_MINUTES', 30),
    'session_stale_after_minutes' => (int) env('HARDWARE_SESSION_STALE_AFTER_MINUTES', 15),
    'default_max_retries' => (int) env('HARDWARE_COMMAND_MAX_RETRIES', 3),
    'retry_backoff_base_seconds' => (int) env('HARDWARE_RETRY_BACKOFF_BASE_SECONDS', 15),
    'retry_backoff_cap_seconds' => (int) env('HARDWARE_RETRY_BACKOFF_CAP_SECONDS', 300),
    'degraded_queue_depth_threshold' => (int) env('HARDWARE_DEGRADED_QUEUE_DEPTH_THRESHOLD', 20),
    'pull_command_default_limit' => (int) env('HARDWARE_PULL_COMMAND_DEFAULT_LIMIT', 25),
    'runtime' => [
        'version_contract' => env('HARDWARE_RUNTIME_VERSION_CONTRACT', '16.3.0'),
        'update_channel_default' => env('HARDWARE_RUNTIME_UPDATE_CHANNEL_DEFAULT', 'stable'),
        'watchdog_stall_spool_seconds' => (int) env('HARDWARE_WATCHDOG_STALL_SPOOL_SECONDS', 90),
    ],
];
