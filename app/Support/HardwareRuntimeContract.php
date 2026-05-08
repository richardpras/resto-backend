<?php

namespace App\Support;

class HardwareRuntimeContract
{
    /** @var list<string> */
    public const RUNTIME_STATES = [
        'connected',
        'disconnected',
        'reconnecting',
        'stale',
        'recovering',
        'degraded',
    ];

    /** @var array<string,string> */
    private const LEGACY_TO_SPOOL = [
        'queued' => 'pending',
        'pending' => 'pending',
        'processing' => 'processing',
        'acknowledged' => 'acknowledged',
        'failed' => 'failed',
        'nacked' => 'failed',
        'replay_pending' => 'replay_pending',
        'dead_letter' => 'dead_letter',
    ];

    public static function normalizeRuntimeState(?string $state, string $fallback = 'connected'): string
    {
        $normalized = strtolower(trim((string) $state));
        if (in_array($normalized, self::RUNTIME_STATES, true)) {
            return $normalized;
        }

        return $fallback;
    }

    public static function toSpoolStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return self::LEGACY_TO_SPOOL[$normalized] ?? 'pending';
    }

    public static function toLegacyStatus(string $spoolStatus): string
    {
        return match ($spoolStatus) {
            'pending' => 'queued',
            'processing' => 'processing',
            'acknowledged' => 'acknowledged',
            'failed' => 'nacked',
            'replay_pending' => 'nacked',
            'dead_letter' => 'dead_letter',
            default => 'queued',
        };
    }
}
