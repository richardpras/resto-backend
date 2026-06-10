<?php

namespace App\Modules\Payments\Services;

final class PaymentHealthSeverityEngine
{
    public const SEVERITY_HEALTHY = 'healthy';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    /** @var array<string, int> */
    private const SEVERITY_RANK = [
        self::SEVERITY_HEALTHY => 0,
        self::SEVERITY_WARNING => 1,
        self::SEVERITY_HIGH => 2,
        self::SEVERITY_CRITICAL => 3,
    ];

    public function configurationSeverity(string $configStatus): string
    {
        return match ($configStatus) {
            'critical' => self::SEVERITY_CRITICAL,
            'warning' => self::SEVERITY_WARNING,
            default => self::SEVERITY_HEALTHY,
        };
    }

    public function rateSeverity(float $successRate): string
    {
        if ($successRate >= 99.0) {
            return self::SEVERITY_HEALTHY;
        }
        if ($successRate >= 95.0) {
            return self::SEVERITY_WARNING;
        }
        if ($successRate >= 90.0) {
            return self::SEVERITY_HIGH;
        }

        return self::SEVERITY_CRITICAL;
    }

    public function stalePaymentsSeverity(int $count): string
    {
        if ($count <= 5) {
            return self::SEVERITY_HEALTHY;
        }
        if ($count <= 20) {
            return self::SEVERITY_WARNING;
        }
        if ($count <= 50) {
            return self::SEVERITY_HIGH;
        }

        return self::SEVERITY_CRITICAL;
    }

    /**
     * @param  list<string>  $severities
     */
    public function aggregateSeverity(array $severities): string
    {
        $worst = self::SEVERITY_HEALTHY;
        foreach ($severities as $severity) {
            if ((self::SEVERITY_RANK[$severity] ?? 0) > (self::SEVERITY_RANK[$worst] ?? 0)) {
                $worst = $severity;
            }
        }

        return $worst;
    }

    public function isWorsening(string $previous, string $current): bool
    {
        return (self::SEVERITY_RANK[$current] ?? 0) > (self::SEVERITY_RANK[$previous] ?? 0);
    }

    public function severityRank(string $severity): int
    {
        return self::SEVERITY_RANK[$severity] ?? 0;
    }
}
