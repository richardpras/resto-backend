<?php

namespace App\Modules\Notifications\Services\Adapters;

use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Accounting\Services\AccountingHealthService;
use App\Modules\Notifications\Services\NotificationService;

final class AccountingNotificationAdapter
{
    private const HEALTH_SCORE_THRESHOLD = 70;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly AccountingHealthService $accountingHealthService,
    ) {}

    public function notifyPostingFailure(AccountingPostingFailure $failure): void
    {
        $outletId = (int) ($failure->outlet_id ?? 0);
        if ($outletId < 1) {
            return;
        }

        $this->notificationService->fanOut(
            $outletId,
            'accounting.manage',
            UserNotification::SEVERITY_CRITICAL,
            UserNotification::MODULE_ACCOUNTING,
            'posting_failure',
            (string) $failure->id,
            'Accounting posting failed',
            (string) $failure->error_message,
            '/accounting?tab=health',
            [
                'failureId' => (int) $failure->id,
                'errorCode' => (string) $failure->error_code,
                'sourceType' => (string) $failure->source_type,
                'sourceId' => (int) $failure->source_id,
            ],
        );
    }

    public function syncHealthAlerts(?int $outletId): void
    {
        if ($outletId === null || $outletId < 1) {
            return;
        }

        $report = $this->accountingHealthService->report(null, $outletId);
        $healthScore = (int) ($report['healthScore'] ?? 100);

        if ($healthScore < self::HEALTH_SCORE_THRESHOLD) {
            $this->notificationService->fanOut(
                $outletId,
                'accounting.manage',
                UserNotification::SEVERITY_CRITICAL,
                UserNotification::MODULE_ACCOUNTING,
                'health_score_low',
                (string) $outletId,
                'Accounting health score is low',
                sprintf('Accounting health score is %d (threshold %d).', $healthScore, self::HEALTH_SCORE_THRESHOLD),
                '/accounting?tab=health',
                ['healthScore' => $healthScore],
            );
        }

        $giftCardStatus = (string) ($report['giftCardReconciliationStatus'] ?? '');
        if ($giftCardStatus === 'variance') {
            $variance = (float) ($report['giftCardVariance'] ?? 0);
            $this->notificationService->fanOut(
                $outletId,
                'accounting.manage',
                UserNotification::SEVERITY_CRITICAL,
                UserNotification::MODULE_ACCOUNTING,
                'gift_card_variance',
                (string) $outletId,
                'Gift card reconciliation variance',
                sprintf('Gift card subledger variance detected (%.2f).', $variance),
                '/accounting?tab=reconciliation',
                [
                    'giftCardVariance' => $variance,
                    'status' => $giftCardStatus,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function notifySeverityEscalation(
        int $outletId,
        string $previousSeverity,
        string $currentSeverity,
        array $report,
    ): void {
        $severity = match ($currentSeverity) {
            'critical' => UserNotification::SEVERITY_CRITICAL,
            'high' => UserNotification::SEVERITY_WARNING,
            'warning' => UserNotification::SEVERITY_INFO,
            default => UserNotification::SEVERITY_INFO,
        };

        $this->notificationService->fanOut(
            $outletId,
            'accounting.manage',
            $severity,
            UserNotification::MODULE_ACCOUNTING,
            'health_severity_escalation',
            $outletId.'-'.$currentSeverity,
            'Accounting health severity escalated',
            sprintf(
                'Accounting health worsened from %s to %s (score %d).',
                $previousSeverity,
                $currentSeverity,
                (int) ($report['healthScore'] ?? 0),
            ),
            '/accounting?tab=health',
            [
                'previousSeverity' => $previousSeverity,
                'currentSeverity' => $currentSeverity,
                'healthScore' => (int) ($report['healthScore'] ?? 0),
                'priorityQueue' => $report['priorityQueue'] ?? [],
            ],
        );
    }
}
