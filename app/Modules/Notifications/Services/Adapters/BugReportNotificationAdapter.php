<?php

namespace App\Modules\Notifications\Services\Adapters;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\System\Domain\BugReport;
use App\Modules\Notifications\Services\NotificationService;

final class BugReportNotificationAdapter
{
    public const TYPE_CREATED = 'bug_report_created';

    public const TYPE_STATUS_UPDATED = 'bug_report_status_updated';

    private const RECIPIENT_PERMISSION = 'settings.manage';

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function notifyCreated(BugReport $report): void
    {
        $outletId = (int) ($report->outlet_id ?? 0);
        if ($outletId < 1) {
            return;
        }

        $this->notificationService->fanOut(
            $outletId,
            self::RECIPIENT_PERMISSION,
            $this->mapSeverity($report->severity),
            UserNotification::MODULE_SYSTEM,
            self::TYPE_CREATED,
            (string) $report->id,
            'New bug report: '.$report->title,
            sprintf('Reported on %s — %s', $report->current_route ?? 'unknown route', $report->severity),
            '/system/bug-reports/'.$report->id,
            [
                'bugReportId' => (int) $report->id,
                'severity' => $report->severity,
                'reporterUserId' => (int) $report->reporter_user_id,
            ],
        );
    }

    public function notifyStatusUpdated(BugReport $report, string $previousStatus): void
    {
        $outletId = (int) ($report->outlet_id ?? 0);
        if ($outletId < 1 || $report->reporter_user_id === null) {
            return;
        }

        $this->notificationService->create(
            $outletId,
            (int) $report->reporter_user_id,
            UserNotification::SEVERITY_INFO,
            UserNotification::MODULE_SYSTEM,
            self::TYPE_STATUS_UPDATED,
            sprintf('%d-%s', (int) $report->id, $report->status),
            'Bug report status updated',
            sprintf('Your report "%s" changed from %s to %s.', $report->title, $previousStatus, $report->status),
            '/system/bug-reports/'.$report->id,
            [
                'bugReportId' => (int) $report->id,
                'previousStatus' => $previousStatus,
                'status' => $report->status,
            ],
        );
    }

    private function mapSeverity(string $severity): string
    {
        return match ($severity) {
            BugReport::SEVERITY_CRITICAL => UserNotification::SEVERITY_CRITICAL,
            BugReport::SEVERITY_HIGH => UserNotification::SEVERITY_WARNING,
            default => UserNotification::SEVERITY_INFO,
        };
    }
}
