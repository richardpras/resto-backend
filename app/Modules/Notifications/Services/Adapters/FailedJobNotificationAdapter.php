<?php

namespace App\Modules\Notifications\Services\Adapters;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\System\Services\FailedJobSeverityEngine;

final class FailedJobNotificationAdapter
{
    public const TYPE_FAILED_JOB = 'failed_job';

    public const TYPE_FAILED_JOB_SPIKE = 'failed_job_spike';

    public const TYPE_FAILED_JOB_CRITICAL = 'failed_job_critical';

    private const RECIPIENT_PERMISSION = 'settings.manage';

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly FailedJobSeverityEngine $severityEngine,
    ) {}

    public function notifyJobFailed(int $outletId, string $jobClass, string $jobSeverity, string $jobUuid): void
    {
        if ($outletId < 1) {
            return;
        }

        $ncSeverity = $this->mapNcSeverity($jobSeverity);
        $sourceId = sprintf('%d-%s-%s', $outletId, $jobClass, $jobSeverity);

        $this->notificationService->fanOut(
            $outletId,
            self::RECIPIENT_PERMISSION,
            $ncSeverity,
            UserNotification::MODULE_SYSTEM,
            self::TYPE_FAILED_JOB,
            $sourceId,
            'Background job failed: '.$jobClass,
            sprintf('Queue job %s failed (%s severity). Review failed jobs dashboard.', $jobClass, $jobSeverity),
            '/system/failed-jobs',
            [
                'jobClass' => $jobClass,
                'jobSeverity' => $jobSeverity,
                'jobUuid' => $jobUuid,
                'domainSeverity' => $jobSeverity === FailedJobSeverityEngine::JOB_TIER_CRITICAL ? 'critical' : $jobSeverity,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function notifySpike(int $outletId, array $summary): void
    {
        if ($outletId < 1) {
            return;
        }

        $failedJobs = (int) ($summary['failedJobs'] ?? 0);
        $healthStatus = (string) ($summary['healthStatus'] ?? FailedJobSeverityEngine::TIER_WARNING);
        $sourceId = sprintf('%d-spike-%s', $outletId, $healthStatus);

        $this->notificationService->fanOut(
            $outletId,
            self::RECIPIENT_PERMISSION,
            UserNotification::SEVERITY_WARNING,
            UserNotification::MODULE_SYSTEM,
            self::TYPE_FAILED_JOB_SPIKE,
            $sourceId,
            'Failed job spike detected',
            sprintf('%d failed queue job(s) detected (status: %s).', $failedJobs, $healthStatus),
            '/system/failed-jobs',
            [
                'summary' => $summary,
                'domainSeverity' => $healthStatus,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function notifyCriticalThreshold(int $outletId, array $summary): void
    {
        if ($outletId < 1) {
            return;
        }

        $criticalFailures = (int) ($summary['criticalFailures'] ?? 0);
        $sourceId = sprintf('%d-critical-%s', $outletId, now()->toDateString());

        $this->notificationService->fanOut(
            $outletId,
            self::RECIPIENT_PERMISSION,
            UserNotification::SEVERITY_CRITICAL,
            UserNotification::MODULE_SYSTEM,
            self::TYPE_FAILED_JOB_CRITICAL,
            $sourceId,
            'Critical queue failures detected',
            sprintf(
                '%d critical queue failure(s); %d total failed job(s) require attention.',
                $criticalFailures,
                (int) ($summary['failedJobs'] ?? 0),
            ),
            '/system/failed-jobs',
            [
                'summary' => $summary,
                'domainSeverity' => 'critical',
            ],
        );
    }

    private function mapNcSeverity(string $jobSeverity): string
    {
        return match ($jobSeverity) {
            FailedJobSeverityEngine::JOB_TIER_CRITICAL => UserNotification::SEVERITY_CRITICAL,
            FailedJobSeverityEngine::JOB_TIER_WARNING => UserNotification::SEVERITY_WARNING,
            default => UserNotification::SEVERITY_INFO,
        };
    }
}
