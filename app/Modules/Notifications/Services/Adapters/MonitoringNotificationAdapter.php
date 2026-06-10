<?php

namespace App\Modules\Notifications\Services\Adapters;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\NotificationService;

final class MonitoringNotificationAdapter
{
    /**
     * @param  array<string, mixed>  $monitoringMetrics
     */
    public function syncFromMetrics(int $outletId, array $monitoringMetrics): void
    {
        if ($outletId < 1) {
            return;
        }

        $printerFailed = (int) (($monitoringMetrics['printerQueue']['failed'] ?? 0));
        $printerDeadLetter = (int) (($monitoringMetrics['printerQueue']['deadLetter'] ?? 0));
        if ($printerFailed > 0 || $printerDeadLetter > 0) {
            $this->notificationService->fanOut(
                $outletId,
                'pos.use',
                UserNotification::SEVERITY_WARNING,
                UserNotification::MODULE_MONITORING,
                'printer_queue_failures',
                (string) $outletId,
                'Printer queue failures',
                sprintf('Printer queue: %d failed, %d dead letter.', $printerFailed, $printerDeadLetter),
                '/',
                ['failed' => $printerFailed, 'deadLetter' => $printerDeadLetter],
            );
        }

        $hardware = is_array($monitoringMetrics['hardwareBridge'] ?? null) ? $monitoringMetrics['hardwareBridge'] : [];
        $staleBridges = (int) ($hardware['staleBridges'] ?? 0);
        $deadLetterCount = (int) ($hardware['deadLetterCount'] ?? 0);
        if ($staleBridges > 0 || $deadLetterCount > 0) {
            $severity = $staleBridges > 0 ? UserNotification::SEVERITY_CRITICAL : UserNotification::SEVERITY_WARNING;
            $this->notificationService->fanOut(
                $outletId,
                'pos.use',
                $severity,
                UserNotification::MODULE_MONITORING,
                'hardware_bridge_offline',
                (string) $outletId,
                'Hardware bridge issues detected',
                sprintf('Stale bridges: %d. Dead letter commands: %d.', $staleBridges, $deadLetterCount),
                '/settings?tab=printers',
                ['staleBridges' => $staleBridges, 'deadLetterCount' => $deadLetterCount],
            );
        }

        $offline = is_array($monitoringMetrics['offlineResilience'] ?? null) ? $monitoringMetrics['offlineResilience'] : [];
        $syncFailures = (int) ($offline['syncReplayFailures'] ?? 0);
        $syncConflicts = (int) ($offline['syncConflictOperations'] ?? 0);
        if ($syncFailures > 0 || $syncConflicts > 0) {
            $this->notificationService->fanOut(
                $outletId,
                'pos.use',
                UserNotification::SEVERITY_WARNING,
                UserNotification::MODULE_MONITORING,
                'offline_sync_failures',
                (string) $outletId,
                'Offline sync failures detected',
                sprintf('Sync replay failures: %d. Conflict operations: %d.', $syncFailures, $syncConflicts),
                '/',
                ['syncReplayFailures' => $syncFailures, 'syncConflictOperations' => $syncConflicts],
            );
        }
    }
}
