<?php

namespace App\Modules\System\Services;

final class FailedJobSeverityEngine
{
    public const TIER_CRITICAL = 'critical';

    public const TIER_HIGH = 'high';

    public const TIER_WARNING = 'warning';

    public const TIER_HEALTHY = 'healthy';

    public const JOB_TIER_CRITICAL = 'critical';

    public const JOB_TIER_WARNING = 'warning';

    public const JOB_TIER_INFO = 'info';

    /**
     * @param  array{
     *     failedJobs: int,
     *     criticalFailures: int,
     *     oldestFailureMinutes: int|null
     * }  $summary
     */
    public function aggregateHealth(array $summary): string
    {
        $failedJobs = (int) ($summary['failedJobs'] ?? 0);
        $criticalFailures = (int) ($summary['criticalFailures'] ?? 0);
        $oldestMinutes = $summary['oldestFailureMinutes'] ?? null;

        if ($criticalFailures > 5 || ($criticalFailures > 0 && $oldestMinutes !== null && $oldestMinutes > 30)) {
            return self::TIER_CRITICAL;
        }

        if ($failedJobs > 10) {
            return self::TIER_HIGH;
        }

        if ($failedJobs > 3) {
            return self::TIER_WARNING;
        }

        return self::TIER_HEALTHY;
    }

    public function classifyJobClass(string $jobClass): string
    {
        $normalized = strtolower($jobClass);

        if (
            str_contains($normalized, 'payment')
            || str_contains($normalized, 'accounting')
            || str_contains($normalized, 'posting')
            || str_contains($normalized, 'journal')
            || str_contains($normalized, 'sync')
            || str_contains($normalized, 'reconcile')
            || str_contains($normalized, 'webhook')
        ) {
            return self::JOB_TIER_CRITICAL;
        }

        if (
            str_contains($normalized, 'report')
            || str_contains($normalized, 'analytics')
            || str_contains($normalized, 'automation')
            || str_contains($normalized, 'snapshot')
            || str_contains($normalized, 'menu')
            || str_contains($normalized, 'loyalty')
        ) {
            return self::JOB_TIER_WARNING;
        }

        return self::JOB_TIER_INFO;
    }

    public function moduleFromJobClass(string $jobClass): string
    {
        $normalized = strtolower($jobClass);

        if (str_contains($normalized, 'payment')) {
            return 'payments';
        }
        if (str_contains($normalized, 'accounting') || str_contains($normalized, 'journal') || str_contains($normalized, 'posting')) {
            return 'accounting';
        }
        if (str_contains($normalized, 'menu')) {
            return 'menu';
        }
        if (str_contains($normalized, 'loyalty')) {
            return 'loyalty';
        }
        if (str_contains($normalized, 'reservation')) {
            return 'reservations';
        }
        if (str_contains($normalized, 'print')) {
            return 'print';
        }
        if (str_contains($normalized, 'sync') || str_contains($normalized, 'terminal')) {
            return 'sync';
        }

        return 'system';
    }

    public function healthScore(string $healthStatus): int
    {
        return match ($healthStatus) {
            self::TIER_CRITICAL => 25,
            self::TIER_HIGH => 50,
            self::TIER_WARNING => 75,
            default => 100,
        };
    }
}
