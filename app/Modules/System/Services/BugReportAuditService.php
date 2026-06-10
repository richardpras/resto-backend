<?php

namespace App\Modules\System\Services;

use App\Models\Modules\System\Domain\BugReport;
use App\Models\User;
use App\Modules\Orders\Services\PosAuditLogService;

final class BugReportAuditService
{
    public function __construct(
        private readonly PosAuditLogService $auditLog,
    ) {}

    public function logCreated(BugReport $report, ?User $actor = null): void
    {
        $this->log('bug_report.created', $report, $actor, [
            'title' => $report->title,
            'severity' => $report->severity,
            'status' => $report->status,
            'currentRoute' => $report->current_route,
        ]);
    }

    public function logAssigned(BugReport $report, ?User $actor, ?int $previousAssigneeId): void
    {
        $this->log('bug_report.assigned', $report, $actor, [
            'assignedToUserId' => $report->assigned_to_user_id,
            'previousAssigneeId' => $previousAssigneeId,
        ]);
    }

    public function logStatusChanged(BugReport $report, ?User $actor, string $previousStatus): void
    {
        $eventType = in_array($report->status, [BugReport::STATUS_CLOSED, BugReport::STATUS_WONT_FIX], true)
            ? 'bug_report.closed'
            : 'bug_report.status_changed';

        $this->log($eventType, $report, $actor, [
            'previousStatus' => $previousStatus,
            'status' => $report->status,
        ]);
    }

    public function logCommented(BugReport $report, ?User $actor, int $commentId): void
    {
        $this->log('bug_report.commented', $report, $actor, [
            'commentId' => $commentId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function log(string $eventType, BugReport $report, ?User $actor, array $payload): void
    {
        $this->auditLog->log(
            $eventType,
            'bug_report',
            (int) $report->id,
            $report->outlet_id !== null ? (int) $report->outlet_id : null,
            $actor,
            $payload,
        );
    }
}
