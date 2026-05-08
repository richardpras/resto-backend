<?php

return [
    /**
     * Reject replay when client_occurred_at is older than this many hours (server clock).
     */
    'replay_max_age_hours' => (int) env('TERMINAL_REPLAY_MAX_AGE_HOURS', 336),

    /**
     * Devices without heartbeat longer than this are considered stale for monitoring.
     */
    'stale_after_minutes' => (int) env('TERMINAL_STALE_AFTER_MINUTES', 120),

    /**
     * Heartbeat gap (minutes) that counts as a reconnect for reconnect_count bumps.
     */
    'reconnect_gap_minutes' => (int) env('TERMINAL_RECONNECT_GAP_MINUTES', 30),
];
