<?php

namespace App\Modules\Notifications\Services;

use App\Models\Modules\HR\Domain\LeaveRequest;
use App\Models\Modules\HR\Domain\OvertimeRequest;
use App\Models\Modules\HR\Domain\PayrollRunAudit;
use App\Models\Modules\HR\Domain\PayrollRunV2;
use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\User;
use App\Modules\Procurement\Models\PurchaseRequest;
use Illuminate\Support\Collection;

final class ApprovalNotificationService
{
    public const TYPE_PURCHASE_REQUEST_PENDING = 'purchase_request_pending_approval';

    public const TYPE_PURCHASE_REQUEST_APPROVED = 'purchase_request_approved';

    public const TYPE_PURCHASE_REQUEST_REJECTED = 'purchase_request_rejected';

    public const TYPE_PURCHASE_ORDER_PENDING = 'purchase_order_pending_approval';

    public const TYPE_PURCHASE_ORDER_APPROVED = 'purchase_order_approved';

    public const TYPE_PURCHASE_ORDER_REJECTED = 'purchase_order_rejected';

    public const TYPE_PAYROLL_RUN_PENDING = 'payroll_run_pending_approval';

    public const TYPE_PAYROLL_RUN_APPROVED = 'payroll_run_approved';

    public const TYPE_PAYROLL_RUN_REJECTED = 'payroll_run_rejected';

    public const TYPE_LEAVE_REQUEST_PENDING = 'leave_request_pending_approval';

    public const TYPE_LEAVE_REQUEST_APPROVED = 'leave_request_approved';

    public const TYPE_LEAVE_REQUEST_REJECTED = 'leave_request_rejected';

    public const TYPE_OVERTIME_REQUEST_PENDING = 'overtime_request_pending_approval';

    public const TYPE_OVERTIME_REQUEST_APPROVED = 'overtime_request_approved';

    public const TYPE_OVERTIME_REQUEST_REJECTED = 'overtime_request_rejected';

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly NotificationRecipientResolver $recipientResolver,
    ) {}

    public function purchaseRequestSubmitted(PurchaseRequest $purchaseRequest, User $actor): void
    {
        $outletId = (int) $purchaseRequest->outlet_id;
        $sourceId = (string) $purchaseRequest->id;
        $label = (string) ($purchaseRequest->request_no ?? $sourceId);

        $this->notifyApprovers(
            $outletId,
            'purchase.manage',
            UserNotification::MODULE_PROCUREMENT,
            self::TYPE_PURCHASE_REQUEST_PENDING,
            $sourceId,
            UserNotification::SEVERITY_INFO,
            'Purchase request pending approval',
            sprintf('Purchase request %s is awaiting approval.', $label),
            '/purchases?tab=requests&id='.$sourceId,
            [$actor->id],
        );
    }

    public function purchaseRequestApproved(PurchaseRequest $purchaseRequest, User $actor): void
    {
        $this->notifyRequester(
            (int) $purchaseRequest->outlet_id,
            $this->resolvePurchaseRequestSubmitterId((int) $purchaseRequest->id),
            UserNotification::MODULE_PROCUREMENT,
            self::TYPE_PURCHASE_REQUEST_APPROVED,
            (string) $purchaseRequest->id,
            UserNotification::SEVERITY_SUCCESS,
            'Purchase request approved',
            sprintf('Purchase request %s was approved.', (string) ($purchaseRequest->request_no ?? $purchaseRequest->id)),
            '/purchases?tab=requests&id='.$purchaseRequest->id,
        );
    }

    public function purchaseRequestRejected(PurchaseRequest $purchaseRequest, User $actor): void
    {
        $this->notifyRequester(
            (int) $purchaseRequest->outlet_id,
            $this->resolvePurchaseRequestSubmitterId((int) $purchaseRequest->id),
            UserNotification::MODULE_PROCUREMENT,
            self::TYPE_PURCHASE_REQUEST_REJECTED,
            (string) $purchaseRequest->id,
            UserNotification::SEVERITY_WARNING,
            'Purchase request rejected',
            sprintf('Purchase request %s was rejected.', (string) ($purchaseRequest->request_no ?? $purchaseRequest->id)),
            '/purchases?tab=requests&id='.$purchaseRequest->id,
        );
    }

    public function purchaseOrderSubmitted(PurchaseOrder $purchaseOrder, User $actor): void
    {
        $outletId = (int) $purchaseOrder->outlet_id;
        $sourceId = (string) $purchaseOrder->id;
        $label = (string) ($purchaseOrder->number ?? $sourceId);

        $this->notifyApprovers(
            $outletId,
            'purchase.manage',
            UserNotification::MODULE_PROCUREMENT,
            self::TYPE_PURCHASE_ORDER_PENDING,
            $sourceId,
            UserNotification::SEVERITY_INFO,
            'Purchase order pending approval',
            sprintf('Purchase order %s is awaiting approval.', $label),
            '/purchases?tab=orders&id='.$sourceId,
            [$actor->id],
        );
    }

    public function purchaseOrderApproved(PurchaseOrder $purchaseOrder, User $actor): void
    {
        $submitterId = (int) ($purchaseOrder->submitted_by ?? 0);
        $this->notifyRequester(
            (int) $purchaseOrder->outlet_id,
            $submitterId > 0 ? $submitterId : null,
            UserNotification::MODULE_PROCUREMENT,
            self::TYPE_PURCHASE_ORDER_APPROVED,
            (string) $purchaseOrder->id,
            UserNotification::SEVERITY_SUCCESS,
            'Purchase order approved',
            sprintf('Purchase order %s was approved.', (string) ($purchaseOrder->number ?? $purchaseOrder->id)),
            '/purchases?tab=orders&id='.$purchaseOrder->id,
        );
    }

    public function purchaseOrderRejected(PurchaseOrder $purchaseOrder, User $actor): void
    {
        $submitterId = (int) ($purchaseOrder->submitted_by ?? 0);
        $this->notifyRequester(
            (int) $purchaseOrder->outlet_id,
            $submitterId > 0 ? $submitterId : null,
            UserNotification::MODULE_PROCUREMENT,
            self::TYPE_PURCHASE_ORDER_REJECTED,
            (string) $purchaseOrder->id,
            UserNotification::SEVERITY_WARNING,
            'Purchase order rejected',
            sprintf('Purchase order %s was rejected.', (string) ($purchaseOrder->number ?? $purchaseOrder->id)),
            '/purchases?tab=orders&id='.$purchaseOrder->id,
        );
    }

    public function payrollRunPendingApproval(PayrollRunV2 $run, User $actor): void
    {
        $outletId = (int) $run->outlet_id;
        $sourceId = (string) $run->id;

        $this->notifyApprovers(
            $outletId,
            'payroll.manage',
            UserNotification::MODULE_PAYROLL,
            self::TYPE_PAYROLL_RUN_PENDING,
            $sourceId,
            UserNotification::SEVERITY_INFO,
            'Payroll run pending approval',
            sprintf('Payroll run #%s is ready for approval.', $sourceId),
            '/payroll?run='.$sourceId,
            [$actor->id],
        );
    }

    public function payrollRunApproved(PayrollRunV2 $run, User $actor): void
    {
        $outletId = (int) $run->outlet_id;
        $sourceId = (string) $run->id;
        $creatorId = $this->resolvePayrollRunCalculatorId((int) $run->id);

        $recipientIds = collect([$creatorId])
            ->merge(
                $this->recipientResolver
                    ->usersForOutlet($outletId, 'payroll.manage')
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id),
            )
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $this->notifyUsers(
            $outletId,
            $recipientIds,
            UserNotification::MODULE_PAYROLL,
            self::TYPE_PAYROLL_RUN_APPROVED,
            $sourceId,
            UserNotification::SEVERITY_SUCCESS,
            'Payroll run approved',
            sprintf('Payroll run #%s was approved.', $sourceId),
            '/payroll?run='.$sourceId,
        );
    }

    public function payrollRunRejected(PayrollRunV2 $run, User $actor): void
    {
        $this->notifyRequester(
            (int) $run->outlet_id,
            $this->resolvePayrollRunCalculatorId((int) $run->id),
            UserNotification::MODULE_PAYROLL,
            self::TYPE_PAYROLL_RUN_REJECTED,
            (string) $run->id,
            UserNotification::SEVERITY_WARNING,
            'Payroll run rejected',
            sprintf('Payroll run #%s was rejected and returned to draft.', (string) $run->id),
            '/payroll?run='.$run->id,
        );
    }

    public function leaveRequestSubmitted(LeaveRequest $leaveRequest, User $actor): void
    {
        $leaveRequest->loadMissing('employee');
        $outletId = (int) $leaveRequest->outlet_id;
        $sourceId = (string) $leaveRequest->id;
        $employeeName = (string) ($leaveRequest->employee?->full_name ?? 'Employee');

        $this->notifyApprovers(
            $outletId,
            'leave.manage',
            UserNotification::MODULE_HR,
            self::TYPE_LEAVE_REQUEST_PENDING,
            $sourceId,
            UserNotification::SEVERITY_INFO,
            'Leave request pending approval',
            sprintf('Leave request for %s is awaiting approval.', $employeeName),
            '/hr/leave?id='.$sourceId,
            [$actor->id],
        );
    }

    public function leaveRequestApproved(LeaveRequest $leaveRequest, User $actor): void
    {
        $leaveRequest->loadMissing('employee');
        $employeeUserId = (int) ($leaveRequest->employee?->user_id ?? 0);

        $this->notifyRequester(
            (int) $leaveRequest->outlet_id,
            $employeeUserId > 0 ? $employeeUserId : null,
            UserNotification::MODULE_HR,
            self::TYPE_LEAVE_REQUEST_APPROVED,
            (string) $leaveRequest->id,
            UserNotification::SEVERITY_SUCCESS,
            'Leave request approved',
            sprintf('Your leave request (%s to %s) was approved.', $leaveRequest->start_date?->toDateString(), $leaveRequest->end_date?->toDateString()),
            '/hr/leave?id='.$leaveRequest->id,
        );
    }

    public function leaveRequestRejected(LeaveRequest $leaveRequest, User $actor): void
    {
        $leaveRequest->loadMissing('employee');
        $employeeUserId = (int) ($leaveRequest->employee?->user_id ?? 0);

        $this->notifyRequester(
            (int) $leaveRequest->outlet_id,
            $employeeUserId > 0 ? $employeeUserId : null,
            UserNotification::MODULE_HR,
            self::TYPE_LEAVE_REQUEST_REJECTED,
            (string) $leaveRequest->id,
            UserNotification::SEVERITY_WARNING,
            'Leave request rejected',
            sprintf('Your leave request (%s to %s) was rejected.', $leaveRequest->start_date?->toDateString(), $leaveRequest->end_date?->toDateString()),
            '/hr/leave?id='.$leaveRequest->id,
        );
    }

    public function overtimeRequestSubmitted(OvertimeRequest $overtimeRequest, User $actor): void
    {
        $overtimeRequest->loadMissing('employee');
        $outletId = (int) $overtimeRequest->outlet_id;
        $sourceId = (string) $overtimeRequest->id;
        $employeeName = (string) ($overtimeRequest->employee?->full_name ?? 'Employee');

        $this->notifyApprovers(
            $outletId,
            'overtime.manage',
            UserNotification::MODULE_HR,
            self::TYPE_OVERTIME_REQUEST_PENDING,
            $sourceId,
            UserNotification::SEVERITY_INFO,
            'Overtime request pending approval',
            sprintf('Overtime request for %s is awaiting approval.', $employeeName),
            '/hr/overtime?id='.$sourceId,
            [$actor->id],
        );
    }

    public function overtimeRequestApproved(OvertimeRequest $overtimeRequest, User $actor): void
    {
        $overtimeRequest->loadMissing('employee');
        $employeeUserId = (int) ($overtimeRequest->employee?->user_id ?? 0);

        $this->notifyRequester(
            (int) $overtimeRequest->outlet_id,
            $employeeUserId > 0 ? $employeeUserId : null,
            UserNotification::MODULE_HR,
            self::TYPE_OVERTIME_REQUEST_APPROVED,
            (string) $overtimeRequest->id,
            UserNotification::SEVERITY_SUCCESS,
            'Overtime request approved',
            sprintf('Your overtime request on %s was approved.', $overtimeRequest->overtime_date?->toDateString()),
            '/hr/overtime?id='.$overtimeRequest->id,
        );
    }

    public function overtimeRequestRejected(OvertimeRequest $overtimeRequest, User $actor): void
    {
        $overtimeRequest->loadMissing('employee');
        $employeeUserId = (int) ($overtimeRequest->employee?->user_id ?? 0);

        $this->notifyRequester(
            (int) $overtimeRequest->outlet_id,
            $employeeUserId > 0 ? $employeeUserId : null,
            UserNotification::MODULE_HR,
            self::TYPE_OVERTIME_REQUEST_REJECTED,
            (string) $overtimeRequest->id,
            UserNotification::SEVERITY_WARNING,
            'Overtime request rejected',
            sprintf('Your overtime request on %s was rejected.', $overtimeRequest->overtime_date?->toDateString()),
            '/hr/overtime?id='.$overtimeRequest->id,
        );
    }

    /**
     * @param  list<int>  $excludeUserIds
     */
    private function notifyApprovers(
        int $outletId,
        string $permissionCode,
        string $sourceModule,
        string $sourceType,
        string $sourceId,
        string $severity,
        string $title,
        string $message,
        string $actionUrl,
        array $excludeUserIds = [],
    ): Collection {
        if ($outletId < 1) {
            return collect();
        }

        $exclude = array_flip(array_map('intval', $excludeUserIds));
        $recipients = $this->recipientResolver
            ->usersForOutlet($outletId, $permissionCode)
            ->reject(static fn (User $user): bool => isset($exclude[(int) $user->id]));

        return $recipients->map(fn (User $user): UserNotification => $this->notificationService->create(
            $outletId,
            (int) $user->id,
            $severity,
            $sourceModule,
            $sourceType,
            $sourceId,
            $title,
            $message,
            $actionUrl,
            ['workflow' => 'approval'],
        ));
    }

    /**
     * @param  list<int>  $userIds
     */
    private function notifyUsers(
        int $outletId,
        array $userIds,
        string $sourceModule,
        string $sourceType,
        string $sourceId,
        string $severity,
        string $title,
        string $message,
        string $actionUrl,
    ): Collection {
        if ($outletId < 1) {
            return collect();
        }

        return collect($userIds)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->map(fn (int $userId): UserNotification => $this->notificationService->create(
                $outletId,
                $userId,
                $severity,
                $sourceModule,
                $sourceType,
                $sourceId,
                $title,
                $message,
                $actionUrl,
                ['workflow' => 'approval'],
            ));
    }

    private function notifyRequester(
        int $outletId,
        ?int $userId,
        string $sourceModule,
        string $sourceType,
        string $sourceId,
        string $severity,
        string $title,
        string $message,
        string $actionUrl,
    ): ?UserNotification {
        if ($outletId < 1 || $userId === null || $userId < 1) {
            return null;
        }

        return $this->notificationService->create(
            $outletId,
            $userId,
            $severity,
            $sourceModule,
            $sourceType,
            $sourceId,
            $title,
            $message,
            $actionUrl,
            ['workflow' => 'approval'],
        );
    }

    private function resolvePurchaseRequestSubmitterId(int $purchaseRequestId): ?int
    {
        $actorId = PosEventLog::query()
            ->where('event_type', 'purchase_request_submitted')
            ->where('entity_type', 'purchase_request')
            ->where('entity_id', $purchaseRequestId)
            ->orderByDesc('id')
            ->value('actor_user_id');

        return is_numeric($actorId) && (int) $actorId > 0 ? (int) $actorId : null;
    }

    private function resolvePayrollRunCalculatorId(int $payrollRunId): ?int
    {
        $performedBy = PayrollRunAudit::query()
            ->where('payroll_run_id', $payrollRunId)
            ->where('action', PayrollRunAudit::ACTION_CALCULATED)
            ->orderByDesc('id')
            ->value('performed_by');

        return is_numeric($performedBy) && (int) $performedBy > 0 ? (int) $performedBy : null;
    }
}
