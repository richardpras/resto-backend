<?php

namespace App\Modules\Notifications\Services\Adapters;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\System\DTO\UnifiedAuditRecord;
use App\Modules\System\Services\AuditRiskClassificationService;

final class CriticalAuditNotificationAdapter
{
    public const TYPE_CRITICAL_AUDIT_EVENT = 'critical_audit_event';

    private const RECIPIENT_PERMISSION = 'settings.manage';

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function notifyCriticalEvent(int $outletId, UnifiedAuditRecord $record): void
    {
        if ($outletId < 1) {
            return;
        }

        if (($record->metadata['riskLevel'] ?? '') !== AuditRiskClassificationService::RISK_CRITICAL) {
            return;
        }

        $sourceId = sprintf('%s-%s', $outletId, $record->id);

        $this->notificationService->fanOut(
            $outletId,
            self::RECIPIENT_PERMISSION,
            UserNotification::SEVERITY_CRITICAL,
            UserNotification::MODULE_SYSTEM,
            self::TYPE_CRITICAL_AUDIT_EVENT,
            $sourceId,
            'Critical audit event: '.$record->action,
            sprintf(
                '%s performed %s on %s #%d.',
                $record->userName ?? 'System',
                $record->action,
                $record->entityType,
                $record->entityId,
            ),
            '/system/audit',
            [
                'auditId' => $record->id,
                'module' => $record->module,
                'entityType' => $record->entityType,
                'entityId' => $record->entityId,
                'action' => $record->action,
                'riskLevel' => AuditRiskClassificationService::RISK_CRITICAL,
                'domainSeverity' => 'critical',
            ],
        );
    }
}
